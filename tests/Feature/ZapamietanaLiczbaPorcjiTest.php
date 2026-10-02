<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeServingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jawnie zapamiętana własna liczba porcji przy przepisie (#2602, D-333).
 *
 * Mierzymy przez PRAWDZIWE ŻĄDANIA HTTP. Przepis autora jest na 4 porcje
 * („200 g mąki”); osoba zapamiętuje 2 („100 g mąki”).
 *
 * KONTROLA UJEMNA (ręcznie): w `ZapamietanePorcje::zapamietana()` usunięcie
 * warunku `where('user_id', ...)` daje cudzą preferencję drugiemu kontu
 * i oblewa `test_cudza_preferencja_nie_dziala_dla_innego_konta`; zamiana
 * `parametrDla()` na zwrot `null` zamiast `autor` robi z „Pokaż ilości
 * z przepisu” link do ustawienia i oblewa
 * `test_powrot_do_ilosci_autora_dziala_mimo_preferencji_i_nie_kasuje_jej`.
 *
 * @bez-kontroli-dodatniej Test wykonuje żądania HTTP na prawdziwej bazie i sam niesie obie strony pomiaru (jest preferencja / nie ma), nie asertuje na treści źródła.
 */
final class ZapamietanaLiczbaPorcjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bez_zapisu_przepis_pokazuje_ilosci_autora_a_przycisk_jest_przy_wyborze(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();

        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('200 g mąki')
            ->assertDontSee('Zapamiętaj dla mnie')
            ->assertDontSee('Zapomnij moje ustawienie');

        $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 2]))
            ->assertOk()
            ->assertSee('Zapamiętaj dla mnie 2 porcje')
            ->assertSee('100 g</strong> mąki', false);

        $this->assertSame(0, RecipeServingPreference::query()->count(), 'Samo oglądanie nie może niczego zapisywać.');
    }

    public function test_zapis_zmiana_i_usuniecie_jednej_liczby(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();

        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2'])
            ->assertRedirect(route('recipes.show', $przepis->slug).'#skladniki')
            ->assertSessionHas('status');
        $this->assertSame(2.0, $this->zapisana($osoba, $przepis));

        // Zmiana nadpisuje ten sam wiersz.
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '3']);
        $this->assertSame(3.0, $this->zapisana($osoba, $przepis));
        $this->assertSame(1, RecipeServingPreference::query()->count());

        $this->actingAs($osoba)->delete(route('recipes.porcje.destroy', $przepis->slug))
            ->assertRedirect(route('recipes.show', $przepis->slug).'#skladniki');
        $this->assertNull($this->zapisana($osoba, $przepis));

        // Usunięcie nieistniejącego nie jest błędem.
        $this->actingAs($osoba)->delete(route('recipes.porcje.destroy', $przepis->slug))->assertRedirect();
    }

    public function test_zapamietana_liczba_dziala_przy_adresie_bez_porcji_i_jest_widoczna_jako_wlasna(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('100 g</strong> mąki', false)
            ->assertSee('Przeliczone na 2 porcje.')
            ->assertSee('Autor podał ilości na 4 porcje.')
            ->assertSee('To Twoje zapamiętane ustawienie dla tego przepisu.')
            ->assertSee('Zapomnij moje ustawienie')
            ->assertDontSee('Zapamiętaj dla mnie');
    }

    public function test_cudza_preferencja_nie_dziala_dla_innego_konta(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $this->actingAs($inna)->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertSee('200 g mąki')->assertDontSee('100 g</strong> mąki', false)->assertDontSee('zapamiętane ustawienie');

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertSee('200 g mąki')->assertDontSee('100 g</strong> mąki', false)
            ->assertDontSee('Zapamiętaj dla mnie')->assertDontSee('zapamiętane ustawienie');

        // Inna osoba nie usuwa cudzego wyboru.
        $this->actingAs($inna)->delete(route('recipes.porcje.destroy', $przepis->slug))->assertRedirect();
        $this->assertSame(2.0, $this->zapisana($osoba, $przepis));
    }

    public function test_gosc_nie_zapisuje_niczego(): void
    {
        $przepis = $this->przepis();

        $this->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2'])->assertRedirect(route('login'));
        $this->delete(route('recipes.porcje.destroy', $przepis->slug))->assertRedirect(route('login'));

        $this->assertSame(0, RecipeServingPreference::query()->count());
    }

    public function test_jawny_parametr_z_adresu_ma_pierwszenstwo_przed_zapamietana_liczba(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 8]))
            ->assertOk()
            ->assertSee('Przeliczone na 8 porcji.')
            ->assertSee('400 g</strong> mąki', false)
            ->assertSee('Masz zapamiętane ustawienie: 2 porcje.')
            ->assertSee('Pokaż moje ustawienie')
            ->assertSee('Zapamiętaj zamiast tego 8 porcji');
    }

    public function test_bledny_parametr_zachowuje_wyjasnienie_i_nie_udaje_wlasnego_wyboru(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $odpowiedz = $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 'abc<script>']))
            ->assertOk()
            ->assertSee('Tej liczby porcji nie da się przeliczyć. Pokazujemy ilości z przepisu.')
            ->assertSee('200 g mąki')
            ->assertDontSee('To Twoje zapamiętane ustawienie dla tego przepisu.');

        $this->assertStringNotContainsString('abc<script>', (string) $odpowiedz->getContent());
        $this->assertStringNotContainsString('abc&lt;script&gt;', (string) $odpowiedz->getContent(), 'Wartość z adresu nie wraca na stronę.');
    }

    public function test_powrot_do_ilosci_autora_dziala_mimo_preferencji_i_nie_kasuje_jej(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $strona = $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertMatchesRegularExpression(
            '~<a href="[^"]*\?porcje=autor#skladniki">Pokaż ilości z przepisu</a>~u',
            (string) $strona->getContent(),
            '„Pokaż ilości z przepisu” musi prowadzić pod jawne ?porcje=autor, inaczej wraca do ustawienia.',
        );

        $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 'autor']))
            ->assertOk()
            ->assertSee('200 g mąki')
            ->assertDontSee('100 g</strong> mąki', false)
            ->assertDontSee('Przeliczone na')
            ->assertSee('Masz zapamiętane ustawienie: 2 porcje.')
            ->assertSee('Pokaż moje ustawienie');

        $this->assertSame(2.0, $this->zapisana($osoba, $przepis), 'Jednorazowy powrót do oryginału nie kasuje ustawienia.');

        // Dojście „Mniej” do liczby autora też nie wraca do ustawienia.
        $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 3]))
            ->assertOk()
            ->assertSee('?porcje=autor#skladniki', false);

        // Adres bez parametru znowu znaczy „po mojemu”.
        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->assertSee('100 g</strong> mąki', false);
    }

    public function test_link_druku_niesie_to_co_widac_takze_ilosci_autora(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $this->assertStringContainsString(
            'druk=1&amp;porcje=2#jak-wydrukowac',
            (string) $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->getContent(),
        );
        $this->assertStringContainsString(
            'druk=1&amp;porcje=autor#jak-wydrukowac',
            (string) $this->actingAs($osoba)->get(route('recipes.show', [$przepis->slug, 'porcje' => 'autor']))->getContent(),
        );
    }

    #[DataProvider('zleWartosci')]
    public function test_stary_albo_zly_formularz_nie_zapisuje_i_mowi_co_zrobic(mixed $wartosc, string $fragment): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();

        $dane = $wartosc === null ? [] : ['porcje' => $wartosc];
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), $dane)
            ->assertRedirect(route('recipes.show', $przepis->slug).'#skladniki')
            ->assertSessionHas('status', fn ($komunikat) => str_contains(json_encode($komunikat, JSON_UNESCAPED_UNICODE) ?: '', $fragment));

        $this->assertSame(0, RecipeServingPreference::query()->count());
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function zleWartosci(): array
    {
        return [
            'brak pola' => [null, 'Tej liczby porcji nie da się zapamiętać'],
            'litery' => ['dużo', 'Tej liczby porcji nie da się zapamiętać'],
            'zero' => ['0', 'Tej liczby porcji nie da się zapamiętać'],
            'powyzej stu' => ['101', 'Tej liczby porcji nie da się zapamiętać'],
            'tyle co autor' => ['4', 'liczba porcji z przepisu autora'],
        ];
    }

    public function test_przepis_bez_liczby_porcji_nie_jest_skalowany_i_nie_zapisuje(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis(null);

        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2'])
            ->assertSessionHas('status', fn ($k) => str_contains(json_encode($k, JSON_UNESCAPED_UNICODE) ?: '', 'nie ma podanej liczby porcji'));
        $this->assertSame(0, RecipeServingPreference::query()->count());
    }

    public function test_zmieniona_podstawa_uzywa_aktualnej_receptury_a_utrata_podstawy_pozwala_zapomniec(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        // Autor przepisał przepis na 6 porcji: 2 porcje to znowu przeliczenie, od nowej receptury.
        $przepis->forceFill(['servings' => 6])->save();
        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertSee('Autor podał ilości na 6 porcji.')->assertSee('Przeliczone na 2 porcje.');

        // Autor ustawił 2 porcje: zapamiętana liczba jest teraz taka sama jak w przepisie.
        $przepis->forceFill(['servings' => 2])->save();
        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertSee('200 g mąki')->assertDontSee('Przeliczone na')
            ->assertSee('czyli tyle samo')->assertSee('Zapomnij moje ustawienie');

        // Autor usunął liczbę porcji: brak skalowania, ale ustawienie można zapomnieć.
        $przepis->forceFill(['servings' => null])->save();
        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertSee('200 g mąki')->assertDontSee('Przeliczone na')
            ->assertSee('Autor nie podaje teraz liczby porcji przy tym przepisie')
            ->assertSee('Zapomnij moje ustawienie');

        $this->actingAs($osoba)->delete(route('recipes.porcje.destroy', $przepis->slug));
        $this->assertNull($this->zapisana($osoba, $przepis));
    }

    public function test_utrata_widocznosci_przepisu_zamyka_zapis_i_odczyt_mimo_preferencji(): void
    {
        $autor = $this->user();
        $osoba = $this->user();
        $przepis = $this->przepis(4, $autor);
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);
        $this->assertSame(2.0, $this->zapisana($osoba, $przepis));

        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->assertForbidden();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '3'])->assertForbidden();
        $this->actingAs($osoba)->delete(route('recipes.porcje.destroy', $przepis->slug))->assertForbidden();
        $this->assertSame(2.0, $this->zapisana($osoba, $przepis));
    }

    public function test_strona_zalogowanej_osoby_nie_trafia_do_wspolnego_cache(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);

        $naglowek = (string) $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $naglowek);
        $this->assertStringContainsString('no-store', $naglowek);
        $this->assertStringNotContainsString('s-maxage', $naglowek);
    }

    public function test_zmiana_preferencji_nie_rusza_aktywnego_postepu_gotowania(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();
        $postep = app(PostepGotowania::class)->wlacz($osoba, $przepis, [], []);
        app(PostepGotowania::class)->ustawPorcje($postep->fresh(), 10.0);
        $przed = $postep->fresh();

        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);
        $this->actingAs($osoba)->get(route('recipes.show', $przepis->slug))->assertOk();
        // Tryb gotowania trzyma porcje z postępu (10), nie z preferencji (2).
        $adres = (string) $this->actingAs($osoba)->get(route('cooking.show', $przepis->slug))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('porcje=10', $adres);
        $this->actingAs($osoba)->get($adres)->assertOk()->assertSee('500 g</strong> mąki', false);

        $po = $postep->fresh();
        $this->assertEquals($przed->servings, $po->servings);
        $this->assertSame($przed->revision, $po->revision);
        $this->assertSame($przed->servings_revision, $po->servings_revision);
    }

    public function test_limit_zapisow_na_osobe_nie_blokuje_zmiany_istniejacego(): void
    {
        config(['kuking.porcje_zapamietane.limit_na_osobe' => 2]);
        $osoba = $this->user();
        $a = $this->przepis();
        $b = $this->przepis();
        $c = $this->przepis();

        $this->actingAs($osoba)->post(route('recipes.porcje.store', $a->slug), ['porcje' => '2']);
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $b->slug), ['porcje' => '2']);
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $c->slug), ['porcje' => '2'])
            ->assertSessionHas('status', fn ($k) => str_contains(json_encode($k, JSON_UNESCAPED_UNICODE) ?: '', 'Masz już zapamiętaną liczbę porcji przy 2 przepisach'));
        $this->assertNull($this->zapisana($osoba, $c));

        $this->actingAs($osoba)->post(route('recipes.porcje.store', $a->slug), ['porcje' => '3']);
        $this->assertSame(3.0, $this->zapisana($osoba, $a));
    }

    public function test_paczka_z_danymi_zawiera_tylko_wlasne_ustawienia(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);
        $this->actingAs($inna)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '7']);

        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());

        $this->assertCount(1, $dane['zapamietane_porcje']);
        $this->assertSame($przepis->title, $dane['zapamietane_porcje'][0]['przepis']);
        $this->assertSame(2.0, $dane['zapamietane_porcje'][0]['zapamietana_liczba_porcji']);

        $przepis->forceFill(['visibility' => 'private'])->save();
        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());
        $this->assertSame(CollectUserExportData::TRESC_NIEDOSTEPNA, $dane['zapamietane_porcje'][0]['przepis']);
        $this->assertNull($dane['zapamietane_porcje'][0]['adres']);
        $this->assertSame(2.0, $dane['zapamietane_porcje'][0]['zapamietana_liczba_porcji']);
    }

    public function test_wymazanie_konta_usuwa_ustawienia_a_cudze_zostaja(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $przepis = $this->przepis();
        $this->actingAs($osoba)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '2']);
        $this->actingAs($inna)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '7']);

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());

        $this->assertNull($this->zapisana($osoba, $przepis));
        $this->assertSame(7.0, $this->zapisana($inna, $przepis));
    }

    private function zapisana(User $osoba, Recipe $przepis): ?float
    {
        $wiersz = RecipeServingPreference::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->first();

        return $wiersz === null ? null : (float) $wiersz->servings;
    }

    private function przepis(?int $porcje = 4, ?User $autor = null): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => ($autor ?? $this->user())->getKey(),
            'servings' => $porcje,
        ]);

        foreach (['200 g mąki', '2 jajka', 'szczypta soli', '1 łyżka masła'] as $pozycja => $tekst) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'ingredient_text' => $tekst,
                'no_amount' => false,
                'position' => $pozycja,
            ]);
        }

        return $przepis;
    }
}
