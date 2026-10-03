<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookedEvent;
use App\Models\CookingNote;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Prywatny roboczy dopisek podczas gotowania (#2587).
 *
 * Kontrola ujemna dla zadania z issue: dopisek NIE może sam trafić do pola
 * „Coś po swojemu?” (ujawnienie), a dopisek jednego konta albo przepisu nie
 * może pojawić się przy innym (pomylenie próby). „Drugie urządzenie” to druga
 * rewizja formularza.
 */
class DopisekZGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const TEKST = 'dolane 50 ml wody, dłuższy czas';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function przepis(): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);
        foreach ([0, 1] as $i) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
            ]);
        }

        return $recipe;
    }

    private function zapisz(User $osoba, Recipe $recipe, string $tresc = self::TEKST, int $rewizja = 0)
    {
        return $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $recipe->slug), [
            'dopisek' => $tresc,
            'rewizja' => $rewizja,
            'krok' => 2,
        ]);
    }

    private function dopisek(User $osoba, Recipe $recipe): ?CookingNote
    {
        return CookingNote::query()->where('user_id', $osoba->getKey())->where('recipe_id', $recipe->getKey())->first();
    }

    /** Zawartość pola „Coś po swojemu?” na formularzu „Ugotowałem”. */
    private function poleZmian(string $html): string
    {
        $this->assertSame(1, preg_match('~<textarea[^>]*name="changes_note"[^>]*>(.*?)</textarea>~s', $html, $trafienie), 'Formularz nie ma pola changes_note.');

        return html_entity_decode(trim($trafienie[1]));
    }

    public function test_zapisuje_prywatny_dopisek_i_niczego_nie_publikuje(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();

        $this->zapisz($osoba, $recipe)
            ->assertRedirect(route('cooking.show', ['recipe' => $recipe->slug, 'krok' => 2]))
            ->assertSessionHas('status');

        $this->assertSame(self::TEKST, $this->dopisek($osoba, $recipe)?->body);
        $this->assertSame(0, CookedEvent::query()->count(), 'Dopisek nie jest wykonaniem.');
        $this->assertSame(0, Notification::query()->count(), 'Dopisek nikogo nie powiadamia.');
    }

    public function test_strona_gotowania_pokazuje_zapisany_dopisek_tylko_jego_wlascicielowi(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Prywatny dopisek z gotowania (roboczy)')
            ->assertSee(self::TEKST);

        $this->actingAs($inna)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee(self::TEKST);
    }

    public function test_dopisek_nie_przechodzi_na_inny_przepis_ani_na_inne_konto(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $recipe = $this->przepis();
        $drugi = $this->przepis();
        $this->zapisz($osoba, $recipe);
        $this->zapisz($inna, $recipe, 'dosolone', 0);

        $this->actingAs($osoba)->get(route('cooking.show', $drugi->slug))->assertDontSee(self::TEKST);
        $this->actingAs($osoba)->get(route('cooked.create', ['recipe' => $drugi->slug, 'dopisek' => 'wstaw']))
            ->assertDontSee(self::TEKST);
        $this->assertSame('', $this->poleZmian($this->actingAs($osoba)->get(route('cooked.create', ['recipe' => $drugi->slug, 'dopisek' => 'wstaw']))->getContent()));

        $this->assertSame(self::TEKST, $this->dopisek($osoba, $recipe)?->body);
        $this->assertSame('dosolone', $this->dopisek($inna, $recipe)?->body);
        $this->assertSame(2, CookingNote::query()->count());
    }

    public function test_gosc_nie_zapisuje_i_nie_widzi_sekcji_dopisku(): void
    {
        $recipe = $this->przepis();

        $this->post(route('cooking.dopisek.zapisz', $recipe->slug), ['dopisek' => self::TEKST])->assertRedirect(route('login'));
        $this->assertSame(0, CookingNote::query()->count());

        $this->get(route('cooking.show', $recipe->slug))->assertOk()->assertDontSee('Prywatny dopisek z gotowania');
    }

    public function test_dopisek_nie_otwiera_przepisu_niedostepnego_dla_osoby(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $recipe->forceFill(['visibility' => 'private'])->save();

        $this->zapisz($osoba, $recipe)->assertForbidden();
        $this->assertSame(0, CookingNote::query()->count());
    }

    public function test_formularz_ugotowalem_tylko_proponuje_dopisek_i_nie_wstawia_go_sam(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $html = $this->actingAs($osoba)->get(route('cooked.create', $recipe->slug))
            ->assertOk()
            ->assertSee('Masz prywatny dopisek z gotowania')
            ->assertSee('Wstaw dopisek do pola poniżej')
            ->assertSee('podpisany „Po swojemu”', false)
            ->getContent();

        $this->assertSame('', $this->poleZmian((string) $html), 'Dopisek wszedł do pola sam, bez prośby osoby.');
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_przycisk_wstawia_w_biezacym_formularzu_bez_porzucajacego_linku(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $html = (string) $this->actingAs($osoba)->get(route('cooked.create', $recipe->slug))->assertOk()->getContent();
        $this->assertStringContainsString('data-wstaw-dopisek', $html, 'DOPISEK_2857_BEZ_PORZUCAJACEGO_LINKU: przycisk w bieżącym formularzu jest wymagany.');
        $this->assertStringNotContainsString('dopisek=wstaw', $html, 'DOPISEK_2857_BEZ_PORZUCAJACEGO_LINKU: link GET porzuca niewysłane pola i zdjęcie.');
        $this->assertSame('', $this->poleZmian($html));
        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertNotNull($this->dopisek($osoba, $recipe));

        $katalog = getenv('DOPISEK_2857_HTML_KATALOG');
        if (is_string($katalog) && $katalog !== '') {
            file_put_contents($katalog.DIRECTORY_SEPARATOR.'formularz.html', $html);
        }
    }

    public function test_na_prosbe_osoby_dopisek_trafia_do_pola_ale_nic_sie_nie_wysyla(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $html = $this->actingAs($osoba)->get(route('cooked.create', ['recipe' => $recipe->slug, 'dopisek' => 'wstaw']))
            ->assertOk()
            ->assertSee('Wstawiliśmy dopisek z gotowania do pola poniżej')
            ->getContent();

        $this->assertSame(self::TEKST, $this->poleZmian((string) $html));
        $this->assertSame(0, CookedEvent::query()->count(), 'Samo wstawienie nie jest wysłaniem wykonania.');
        $this->assertSame(0, Notification::query()->count());
        $this->assertNotNull($this->dopisek($osoba, $recipe), 'Wstawienie do formularza nie usuwa prywatnego dopisku.');
    }

    public function test_nie_nadpisuje_tekstu_juz_wpisanego_w_polu_po_bledzie_walidacji(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $html = $this->actingAs($osoba)
            ->withSession(['_old_input' => ['changes_note' => 'Moje własne zmiany', 'actual_minutes' => 'abc']])
            ->get(route('cooked.create', ['recipe' => $recipe->slug, 'dopisek' => 'wstaw']))
            ->assertOk()
            ->assertDontSee('Wstaw dopisek do pola poniżej')
            ->assertSee('nie wstawiamy go do formularza, w którym już coś wpisano')
            ->getContent();

        $this->assertSame('Moje własne zmiany', $this->poleZmian((string) $html));
    }

    public function test_zapis_wykonania_konczy_probe_i_usuwa_dopisek_a_wyslany_tekst_zostaje(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $this->actingAs($osoba)->post(route('cooked.store', $recipe->slug), ['changes_note' => self::TEKST])->assertRedirect();

        $this->assertNull($this->dopisek($osoba, $recipe), 'Dopisek przeszedłby do następnego gotowania.');
        $this->assertSame(self::TEKST, CookedEvent::query()->firstOrFail()->changes_note);
    }

    public function test_przestarzala_karta_nie_nadpisuje_nowszego_dopisku(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe, 'pierwsza wersja', 0);
        $this->zapisz($osoba, $recipe, 'druga wersja z telefonu', 1);

        $odpowiedz = $this->zapisz($osoba, $recipe, 'wersja ze starej karty', 1);

        $odpowiedz->assertRedirect()->assertSessionHas('status', fn (string $tresc): bool => str_contains($tresc, 'zmienił się na innym urządzeniu'));
        $odpowiedz->assertSessionHasInput('dopisek', 'wersja ze starej karty');
        $this->assertSame('druga wersja z telefonu', $this->dopisek($osoba, $recipe)?->body);
        $this->assertSame(2, $this->dopisek($osoba, $recipe)->revision);

        $this->actingAs($osoba)->withSession(['_old_input' => ['dopisek' => 'wersja ze starej karty']])
            ->get(route('cooking.show', $recipe->slug))
            ->assertSee('Zapisany teraz dopisek:')
            ->assertSee('druga wersja z telefonu');
    }

    public function test_blad_zapisu_nie_gubi_tekstu_i_nie_mowi_zapisano(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->mock(RoboczyDopisek::class, function ($mock): void {
            $mock->shouldReceive('aktywny')->andReturn(null);
            $mock->shouldReceive('zapisz')->andThrow(new RuntimeException('baza niedostępna'));
        });

        $odpowiedz = $this->zapisz($osoba, $recipe);

        $odpowiedz->assertRedirect()
            ->assertSessionHasInput('dopisek', self::TEKST)
            ->assertSessionHas('status', fn (string $tresc): bool => str_contains($tresc, 'Nie udało się zapisać dopisku') && ! str_contains($tresc, 'Dopisek zapisany'));
        $this->assertSame(0, CookingNote::query()->count());
    }

    public function test_pusty_i_za_dlugi_dopisek_dostaja_polski_komunikat_a_tekst_zostaje(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $dlugi = str_repeat('a', RoboczyDopisek::MAKS_ZNAKOW + 1);

        $this->zapisz($osoba, $recipe, '   ')->assertSessionHasErrors(['dopisek' => 'Wpisz kilka słów dopisku albo wróć do gotowania bez zapisu.']);
        $this->zapisz($osoba, $recipe, $dlugi)
            ->assertSessionHasErrors('dopisek')
            ->assertSessionHasInput('dopisek', $dlugi);
        $this->assertSame(0, CookingNote::query()->count());
    }

    public function test_dopisek_wygasa_i_wygasly_nie_wraca_ani_do_strony_ani_do_formularza(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        Carbon::setTestNow(now()->addHours((int) config('kuking.cooking_note.retention_hours') + 1));

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertDontSee(self::TEKST);
        $html = $this->actingAs($osoba)->get(route('cooked.create', ['recipe' => $recipe->slug, 'dopisek' => 'wstaw']))
            ->assertDontSee('Masz prywatny dopisek z gotowania')
            ->getContent();
        $this->assertSame('', $this->poleZmian((string) $html));

        // Nowy zapis po wygaśnięciu zaczyna od nowa, bez fałszywego konfliktu.
        $this->zapisz($osoba, $recipe, 'nowy dopisek', 1)->assertSessionHas('status');
        $this->assertSame('nowy dopisek', $this->dopisek($osoba, $recipe)?->body);
        $this->assertSame(1, $this->dopisek($osoba, $recipe)->revision);
    }

    public function test_nocne_sprzatanie_kasuje_tylko_wygasle_dopiski(): void
    {
        $osoba = $this->user();
        $zywy = $this->przepis();
        $wygasly = $this->przepis();
        $this->zapisz($osoba, $zywy);
        $this->zapisz($osoba, $wygasly);
        $this->dopisek($osoba, $wygasly)?->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('kuking:sprzataj-postep-gotowania')->assertSuccessful();

        $this->assertNotNull($this->dopisek($osoba, $zywy));
        $this->assertNull($this->dopisek($osoba, $wygasly));
    }

    public function test_osoba_usuwa_dopisek_przyciskiem(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $this->actingAs($osoba)->post(route('cooking.dopisek.usun', $recipe->slug), ['krok' => 1])
            ->assertRedirect(route('cooking.show', ['recipe' => $recipe->slug, 'krok' => 1]));

        $this->assertNull($this->dopisek($osoba, $recipe));
    }

    public function test_cudze_usuniecie_nie_rusza_dopisku_innej_osoby(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);

        $this->actingAs($inna)->post(route('cooking.dopisek.usun', $recipe->slug), ['krok' => 1]);

        $this->assertNotNull($this->dopisek($osoba, $recipe));
    }

    public function test_zapis_dopisku_nie_zmienia_postepu_krokow_w_sesji(): void
    {
        $osoba = $this->user();
        $recipe = $this->przepis();
        $krok = $recipe->steps()->orderBy('position')->firstOrFail();
        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok' => 1, 'krok_id' => $krok->getKey(), 'zrobiono' => '1',
        ]);
        $przed = session('gotowanie.'.$recipe->getKey().'.zrobione');

        $this->zapisz($osoba, $recipe);

        $this->assertSame($przed, session('gotowanie.'.$recipe->getKey().'.zrobione'));
        $this->assertSame([$krok->getKey()], $przed);
    }

    public function test_paczka_danych_zawiera_dopisek_bez_cudzego_a_wymazanie_go_kasuje(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $recipe = $this->przepis();
        $this->zapisz($osoba, $recipe);
        $this->zapisz($inna, $recipe, 'cudzy dopisek');

        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());

        $this->assertCount(1, $dane['dopiski_z_gotowania']);
        $this->assertSame(self::TEKST, $dane['dopiski_z_gotowania'][0]['tresc']);
        $this->assertSame($recipe->title, $dane['dopiski_z_gotowania'][0]['przepis']);

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());

        $this->assertNull($this->dopisek($osoba, $recipe));
        $this->assertNotNull($this->dopisek($inna, $recipe));
    }
}
