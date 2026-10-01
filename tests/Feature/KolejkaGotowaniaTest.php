<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\DziennikWgladu;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolejka kilku przepisów z niezależnymi minutnikami (#2379, D-333).
 *
 * Kolejka żyje w przeglądarce, a serwer dostaje ją w adresie
 * (`?p=slug:krok,...`). Te testy pilnują tego, co leży po stronie serwera:
 * widoczności każdego przepisu przez `RecipePolicy::view`, limitu 4, ekranu
 * bez JavaScriptu (zwykłe linki, żadnego martwego przycisku — D-053)
 * i nawigacji w adresie. Limit, wygasanie i czyszczenie po stronie
 * przeglądarki testuje `resources/js/kolejka-gotowania.test.mjs`.
 */
class KolejkaGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $tytul, int $kroki = 3, ?int $minutnikNaPierwszym = null, string $status = Recipe::STATUS_PUBLISHED): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul, 'visibility' => 'public']);

        // `status` i `published_at` to pola sterujące — poza `$fillable`.
        $przepis->forceFill([
            'status' => $status,
            'published_at' => $status === Recipe::STATUS_PUBLISHED ? now() : null,
        ])->save();

        for ($i = 0; $i < $kroki; $i++) {
            RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => $tytul.': krok '.($i + 1).'.',
                'timer_seconds' => $i === 0 ? $minutnikNaPierwszym : null,
            ]);
        }

        return $przepis->refresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }

    private function element(DOMXPath $xpath, string $zapytanie): DOMElement
    {
        $wezel = $xpath->query($zapytanie)->item(0);

        if (! $wezel instanceof DOMElement) {
            $this->fail('Nie znaleziono elementu: '.$zapytanie);
        }

        return $wezel;
    }

    public function test_gosc_widzi_potrawy_przelacznik_i_linki_do_trybu_pojedynczego(): void
    {
        $autor = $this->user('autor');
        $zupa = $this->przepis($autor, 'Zupa pomidorowa', 5);
        $kotlet = $this->przepis($autor, 'Kotlet schabowy', 4);

        $odpowiedz = $this->get(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$kotlet->slug.':1', 'a' => $zupa->slug]))
            ->assertOk()
            ->assertSee('Zupa pomidorowa')
            ->assertSee('Kotlet schabowy')
            ->assertSee('krok 2 z 5')
            ->assertSee('krok 1 z 4')
            ->assertSee('Zupa pomidorowa: krok 2.')
            ->assertDontSee('Kotlet schabowy: krok 1.', false)
            // Bez JS: zwykły odnośnik do trybu jednego przepisu, ze zgodnym krokiem.
            ->assertSee(route('cooking.show', ['recipe' => $zupa->slug, 'krok' => 2]), false)
            ->assertSee('noindex', false);

        // Przełącznik: link do drugiej potrawy zachowuje kroki obu.
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$kotlet->slug.':1', 'a' => $kotlet->slug])), false);
    }

    public function test_ekran_bez_javascriptu_nie_ma_martwego_przycisku(): void
    {
        $autor = $this->user('autor2');
        $zupa = $this->przepis($autor, 'Zupa', 3, 300);
        $kotlet = $this->przepis($autor, 'Kotlet', 3);

        $html = $this->get(route('kolejka-gotowania', ['p' => $zupa->slug.','.$kotlet->slug]))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // Każdy <button type="button"> (działa tylko ze skryptem) jest ukryty
        // atrybutem `hidden` albo leży w ukrytym bloku — widoczne zostają linki.
        $przyciski = $xpath->query('//*[@data-kolejka-ekran]//button');
        $this->assertGreaterThan(0, $przyciski->length, 'ekran ma przyciski odkrywane skryptem');

        foreach ($przyciski as $przycisk) {
            $this->assertGreaterThan(
                0,
                $xpath->query('ancestor-or-self::*[@hidden]', $przycisk)->length,
                'przycisk bez JS musi być ukryty: '.trim($przycisk->textContent),
            );
        }

        $this->assertStringContainsString('Ustaw sobie kuchenny minutnik na 5 minut', $html);
    }

    public function test_przycisk_dodania_do_kolejki_jest_ukryty_do_czasu_skryptu(): void
    {
        $przepis = $this->przepis($this->user('autor3'), 'Sernik');

        foreach ([route('recipes.show', $przepis->slug), route('cooking.show', $przepis->slug)] as $adres) {
            $xpath = $this->xpath($this->get($adres)->assertOk()->getContent());
            $this->assertSame(1, $xpath->query('//*[@data-kolejka-dodaj]')->length, $adres);
            $blok = $this->element($xpath, '//*[@data-kolejka-dodaj]');

            $this->assertTrue($blok->hasAttribute('hidden'), 'blok „Dodaj do kolejki” ma `hidden` bez JS: '.$adres);
            $this->assertSame($przepis->slug, $blok->getAttribute('data-slug'));
        }
    }

    public function test_przepis_ktorego_osoba_nie_widzi_wypada_z_kolejki_z_komunikatem_i_bez_wycieku(): void
    {
        $autor = $this->user('autor4');
        $widoczny = $this->przepis($autor, 'Rosół');
        $szkic = $this->przepis($autor, 'Tajny szkic babci', 3, null, Recipe::STATUS_DRAFT);
        $ukryty = $this->przepis($autor, 'Przepis ukryty moderacją', 3, null, Recipe::STATUS_HIDDEN);

        $lista = $widoczny->slug.','.$szkic->slug.','.$ukryty->slug.',nie-ma-takiego';

        $this->get(route('kolejka-gotowania', ['p' => $lista]))
            ->assertOk()
            ->assertSee('Rosół')
            ->assertSee('3 przepisy z kolejki są już niedostępne')
            ->assertSee('wypadł z kolejki')
            ->assertDontSee('Tajny szkic babci')
            ->assertDontSee('Przepis ukryty moderacją')
            ->assertDontSee('Tajny szkic babci: krok 1.');
    }

    public function test_autor_nadal_ma_swoj_szkic_w_kolejce_bo_policy_view_go_wpuszcza(): void
    {
        $autor = $this->user('autor5');
        $szkic = $this->przepis($autor, 'Mój szkic', 3, null, Recipe::STATUS_DRAFT);

        $this->actingAs($autor)->get(route('kolejka-gotowania', ['p' => $szkic->slug]))
            ->assertOk()
            ->assertSee('Mój szkic: krok 1.')
            ->assertDontSee('niedostępn');
    }

    public function test_pojedynczy_niedostepny_przepis_ma_komunikat_w_liczbie_pojedynczej_i_pusta_kolejke(): void
    {
        $szkic = $this->przepis($this->user('autor6'), 'Szkic', 3, null, Recipe::STATUS_DRAFT);

        $this->get(route('kolejka-gotowania', ['p' => $szkic->slug]))
            ->assertOk()
            ->assertSee('Jeden przepis z kolejki jest już niedostępny')
            ->assertSee('Kolejka jest pusta')
            ->assertDontSee('Szkic: krok 1.');
    }

    public function test_moderator_wchodzi_w_ukryty_przepis_z_kolejki_i_zostawia_slad_w_dzienniku(): void
    {
        $ukryty = $this->przepis($this->user('autor7'), 'Ukryty garnek', 3, null, Recipe::STATUS_HIDDEN);

        $this->actingAs($this->moderator())->get(route('kolejka-gotowania', ['p' => $ukryty->slug]))
            ->assertOk()
            ->assertSee('Ukryty garnek: krok 1.');

        $this->assertSame(1, AuditLogEntry::query()->where('action', DziennikWgladu::PRZEPIS_UKRYTY)->count());
    }

    public function test_limit_to_cztery_przepisy_reszta_z_adresu_jest_pomijana(): void
    {
        $autor = $this->user('autor8');
        $slugi = [];

        foreach (['Pierwsza', 'Druga', 'Trzecia', 'Czwarta', 'Piąta'] as $tytul) {
            $slugi[] = $this->przepis($autor, $tytul.' potrawa')->slug;
        }

        $this->get(route('kolejka-gotowania', ['p' => implode(',', $slugi)]))
            ->assertOk()
            ->assertSee('Czwarta potrawa')
            ->assertDontSee('Piąta potrawa')
            ->assertSee('najwyżej 4 przepisy')
            ->assertSee('Kolejka (4 z 4)');
    }

    public function test_krok_poza_zakresem_i_smieci_w_adresie_nie_psuja_ekranu(): void
    {
        $zupa = $this->przepis($this->user('autor9'), 'Zupa', 3);

        $this->get(route('kolejka-gotowania', ['p' => $zupa->slug.':9999,../etc/passwd,<script>,:3,,'.$zupa->slug.':1']))
            ->assertOk()
            ->assertSee('Zupa: krok 3.')
            ->assertSee('krok 3 z 3')
            ->assertDontSee('<script>', false);

        $this->get('/gotuj-kilka?p[]=x&a[]=y')->assertOk();
    }

    public function test_nawigacja_krokami_przesuwanie_i_usuwanie_to_linki_w_adresie(): void
    {
        $autor = $this->user('autor10');
        $zupa = $this->przepis($autor, 'Zupa', 4);
        $kotlet = $this->przepis($autor, 'Kotlet', 4);
        $p = $zupa->slug.':2,'.$kotlet->slug.':3';

        $odpowiedz = $this->get(route('kolejka-gotowania', ['p' => $p, 'a' => $kotlet->slug]))->assertOk();

        // Następny/Poprzedni krok zmienia krok tylko aktywnej potrawy.
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$kotlet->slug.':4', 'a' => $kotlet->slug])), false);
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$kotlet->slug.':2', 'a' => $kotlet->slug])), false);
        // Przesunięcie wyżej zamienia kolejność, kroki zostają.
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => $kotlet->slug.':3,'.$zupa->slug.':2', 'a' => $kotlet->slug])), false);
        // Usunięcie aktywnej zostawia resztę.
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => $zupa->slug.':2'])), false);
        // Tryb jednego przepisu w tym samym kroku.
        $odpowiedz->assertSee(e(route('cooking.show', ['recipe' => $kotlet->slug, 'krok' => 3])), false);
    }

    public function test_usuniecie_ostatniej_potrawy_prowadzi_do_pustej_kolejki_a_nie_do_odtworzenia_z_przegladarki(): void
    {
        $zupa = $this->przepis($this->user('autor11'), 'Zupa', 2);

        $odpowiedz = $this->get(route('kolejka-gotowania', ['p' => $zupa->slug]))->assertOk();
        $odpowiedz->assertSee(e(route('kolejka-gotowania', ['p' => ''])), false);

        // `?p=` (pusty, ale obecny) to „zapisz pustą kolejkę”, goły adres to „odtwórz z przeglądarki”.
        $xpath = $this->xpath($this->get(route('kolejka-gotowania', ['p' => '']))->assertOk()->getContent());
        $this->assertSame('1', $this->element($xpath, '//*[@data-kolejka-ekran]')->getAttribute('data-kolejka-z-adresu'));

        $goly = $this->xpath($this->get(route('kolejka-gotowania'))->assertOk()->assertSee('Kolejka jest pusta')->getContent());
        $this->assertSame('0', $this->element($goly, '//*[@data-kolejka-ekran]')->getAttribute('data-kolejka-z-adresu'));
    }

    public function test_przepis_bez_krokow_wypada_z_kolejki(): void
    {
        $autor = $this->user('autor12');
        $bezKrokow = $this->przepis($autor, 'Pusty przepis', 0);
        $zupa = $this->przepis($autor, 'Zupa', 2);

        $this->get(route('kolejka-gotowania', ['p' => $bezKrokow->slug.','.$zupa->slug]))
            ->assertOk()
            ->assertSee('bez opisanych kroków')
            ->assertSee('Zupa: krok 1.')
            ->assertDontSee('Pusty przepis');
    }

    public function test_wygasla_kolejka_pokazuje_komunikat_po_polsku(): void
    {
        $this->get(route('kolejka-gotowania', ['wygasla' => 1]))
            ->assertOk()
            ->assertSee('Kolejka wygasła po 24 godzinach');
    }

    public function test_ostatni_krok_potrawy_daje_ugotowalem_tylko_dla_tej_potrawy(): void
    {
        $autor = $this->user('autor13');
        $zupa = $this->przepis($autor, 'Zupa', 2);
        $kotlet = $this->przepis($autor, 'Kotlet', 3);
        $osoba = $this->user('gotujaca');

        $this->actingAs($osoba)->get(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$kotlet->slug.':1', 'a' => $zupa->slug]))
            ->assertOk()
            ->assertSee('To już ostatni krok: Zupa.')
            ->assertSee(route('cooked.create', $zupa->slug), false)
            ->assertDontSee(route('cooked.create', $kotlet->slug), false);
    }
}
