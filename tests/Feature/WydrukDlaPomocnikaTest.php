<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sharing\KartaZKodemQr;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wydruk „dla pomocnika” (#2345, decyzja właściciela z 1.10.2026, D-333):
 * ten sam `?druk=1` co „Drukuj przepis” (#765), parametr `dla=pomocnika`.
 * Krótsza kartka na blat: bez opisu i „Skąd ten przepis”, z liczbą porcji,
 * składnikami i krokami, opcjonalnie z kodem QR (tylko przepis widoczny dla
 * gościa, ten sam generator co karta #2349). Bez nowego widoku i migracji.
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `&& ! $dlaPomocnika` przy „Skąd ten
 * przepis” oblewa `test_kartka_dla_pomocnika_nie_ma_skad_ten_przepis_ani_opisu`;
 * zdjęcie bramki `$kartaQr->przepisDostepny($recipe)` oblewa
 * `test_prywatny_przepis_autora_nie_dostaje_kodu_qr`.
 */
final class WydrukDlaPomocnikaTest extends TestCase
{
    use RefreshDatabase;

    private const ARKUSZ = 'resources/css/wydruk-przepisu.css';

    private function przepis(array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->family()->create([
            'summary' => 'Opis do pominięcia na kartce pomocnika.',
            'servings' => 4,
            ...$atrybuty,
        ]);

        foreach (['200 g mąki', '2 jajka'] as $pozycja => $tekst) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'no_amount' => false, 'position' => $pozycja]);
        }
        foreach (['Wymieszaj suche.', 'Dodaj jajka.'] as $pozycja => $tekst) {
            RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => $pozycja, 'instruction' => $tekst]);
        }

        return $przepis;
    }

    private function adres(Recipe $przepis, array $parametry = []): string
    {
        return route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1, 'dla' => 'pomocnika', ...$parametry]);
    }

    public function test_strona_przepisu_ma_przycisk_dla_pomocnika_bez_skryptu(): void
    {
        $przepis = $this->przepis();
        $cel = route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1, 'dla' => 'pomocnika']).'#jak-wydrukowac';

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('<a class="btn btn-secondary" href="'.e($cel).'" rel="nofollow">Drukuj dla pomocnika</a>', false)
            ->assertSee('data-drukuj-przepis>Drukuj przepis</a>', false);
    }

    public function test_przycisk_przenosi_wybrane_porcje(): void
    {
        $przepis = $this->przepis();

        $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'porcje' => 6]))
            ->assertOk()
            ->assertSee('druk=1&amp;dla=pomocnika&amp;porcje=6#jak-wydrukowac', false);
    }

    public function test_kartka_dla_pomocnika_nie_ma_skad_ten_przepis_ani_opisu(): void
    {
        $przepis = $this->przepis();

        // Kontrola dodatnia: zwykły wydruk TO ma.
        $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1]))
            ->assertOk()
            ->assertSee('Skąd ten przepis')
            ->assertSee('Robiła to zawsze w niedzielę.')
            ->assertSee('Opis do pominięcia na kartce pomocnika.')
            ->assertDontSee('dla-pomocnika"', false);

        $this->get($this->adres($przepis))
            ->assertOk()
            ->assertDontSee('Skąd ten przepis')
            ->assertDontSee('Robiła to zawsze w niedzielę.')
            ->assertDontSee('text-lead kolumna-czytania', false)
            ->assertSee('przepis-uklad', false)
            ->assertSee('dla-pomocnika', false);
    }

    public function test_kartka_dla_pomocnika_nie_ma_adresu_zrodla_zewnetrznego(): void
    {
        $przepis = $this->przepis(['source_type' => Recipe::SOURCE_EXTERNAL, 'source_url' => 'https://przyklad.example/ciasto']);

        $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1]))
            ->assertOk()
            ->assertSee('Przepis pochodzi ze strony');

        $this->get($this->adres($przepis))
            ->assertOk()
            ->assertDontSee('Przepis pochodzi ze strony')
            ->assertDontSee('przyklad.example');
    }

    public function test_kartka_dla_pomocnika_ma_porcje_skladniki_i_kroki(): void
    {
        $przepis = $this->przepis();

        $this->get($this->adres($przepis))
            ->assertOk()
            ->assertSee('Ilość: 4 porcje')
            ->assertSee('200 g mąki')
            ->assertSee('2 jajka')
            ->assertSee('Wymieszaj suche.')
            ->assertSee('Dodaj jajka.')
            ->assertSee('Jak wydrukować ten przepis:')
            ->assertSee('Na kartce dla pomocnika będzie to, co potrzebne przy blacie');
    }

    public function test_porcje_z_adresu_trafiaja_na_kartke_i_zostaja_po_zmianie(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->get($this->adres($przepis, ['porcje' => 8]))
            ->assertOk()
            ->assertSee('Ilość: 8 porcji')
            ->assertSee('skladnik-przeliczony">400', false)
            ->assertSee('(w przepisie autora: 4 porcje)')
            ->getContent();

        // „Więcej” zostaje na kartce dla pomocnika, nie wraca do zwykłego widoku.
        $this->assertStringContainsString('porcje=9&amp;druk=1&amp;dla=pomocnika#skladniki', $html);
    }

    public function test_zwykly_wydruk_nie_zmienia_sie(): void
    {
        $przepis = $this->przepis();

        $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1]))
            ->assertOk()
            ->assertSee('Na kartce będzie sam przepis — bez menu, przycisków i komentarzy.')
            ->assertDontSee('druk-pomocnik-porcje', false)
            ->assertDontSee('druk-pomocnik-qr', false);

        // `dla=pomocnika` bez `druk=1` to nie wydruk: strona jak zawsze.
        $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'dla' => 'pomocnika']))
            ->assertOk()
            ->assertSee('Skąd ten przepis')
            ->assertDontSee('id="jak-wydrukowac"', false);
    }

    public function test_publiczny_przepis_ma_przelacznik_i_kod_qr_z_adresu_kanonicznego(): void
    {
        $przepis = $this->przepis();
        $karta = app(KartaZKodemQr::class);
        $adresKanoniczny = $karta->adresPrzepisu($przepis);

        // Domyślnie bez kodu, z przyciskiem go dodającego.
        $this->get($this->adres($przepis))
            ->assertOk()
            ->assertDontSee('druk-pomocnik-qr-kod', false)
            ->assertSee('Dodaj kod QR do kartki');

        $html = (string) $this->get($this->adres($przepis, ['qr' => 1, 'porcje' => 6]))
            ->assertOk()
            ->assertSee('druk-pomocnik-qr-kod', false)
            ->assertSee('<p class="druk-pomocnik-qr-adres m-0">'.e($adresKanoniczny).'</p>', false)
            ->assertSee('Bez kodu QR')
            ->getContent();

        // Kod to kod kanonicznego adresu — nie adresu wydruku z parametrami.
        $this->assertStringContainsString($karta->kodSvg($adresKanoniczny), $html);
        $this->assertStringNotContainsString($karta->kodSvg($this->adres($przepis, ['qr' => 1, 'porcje' => 6])), $html);
        $this->assertStringNotContainsString('druk=1', $adresKanoniczny);
    }

    public function test_prywatny_przepis_autora_nie_dostaje_kodu_qr(): void
    {
        $autor = $this->user('autor_pomocnika');
        $przepis = $this->przepis(['author_id' => $autor->getKey(), 'visibility' => 'private']);

        // Autor widzi swój przepis (Policy), ale kod QR otworzyłby 403 gościowi.
        $this->actingAs($autor)->get($this->adres($przepis, ['qr' => 1]))
            ->assertOk()
            ->assertSee('200 g mąki')
            ->assertDontSee('druk-pomocnik-qr', false)
            ->assertDontSee('Dodaj kod QR do kartki')
            ->assertSee('Kod QR jest tylko dla przepisów, które widzi każdy.');
    }

    public function test_obcy_nie_zobaczy_prywatnego_przepisu_w_trybie_pomocnika(): void
    {
        $przepis = $this->przepis(['visibility' => 'private']);

        $odpowiedz = $this->actingAs($this->user('obca_osoba'))->get($this->adres($przepis, ['qr' => 1]));

        $this->assertNotSame(200, $odpowiedz->getStatusCode());
        $odpowiedz->assertDontSee('200 g mąki')->assertDontSee('druk-pomocnik');
    }

    public function test_arkusz_druku_dla_pomocnika_ma_pismo_16_pt_i_kod_qr_min_3_cm(): void
    {
        $css = (string) file_get_contents(base_path(self::ARKUSZ));
        $druk = substr($css, (int) strpos($css, '@media print'));

        $this->assertStringContainsString(
            'body:has(.dla-pomocnika) .przepis-uklad.dla-pomocnika * {'."\n".'    font-size: max(calc(16pt * var(--druk-skala)), 1em);',
            $druk,
            'Wydruk dla pomocnika nie ma w druku progu 16 pt.',
        );

        $this->assertSame(1, preg_match('/\.druk-pomocnik-qr-kod \{\s*width: ([\d.]+)cm;/', $druk, $m), 'Kod QR dla pomocnika nie ma szerokości w cm.');
        $this->assertGreaterThanOrEqual(3.0, (float) $m[1], 'Kod QR na kartce dla pomocnika ma mniej niż 3 cm.');
    }

    public function test_kartka_dla_pomocnika_chowa_objasnienie_zgloszenia_goscia(): void
    {
        $css = (string) file_get_contents(base_path(self::ARKUSZ));

        // Bez tego ostatnia linijka objaśnienia „Zgłoś” spadała na osobną stronę pod kodem QR.
        $this->assertMatchesRegularExpression('/\.dla-pomocnika \.zglos-goscia \{\s*display: none !important;/', $css);
    }

    public function test_parametry_w_adresie_nie_wstrzykuja_html(): void
    {
        $przepis = $this->przepis(['visibility' => 'public']);
        $zlosliwy = '<script>alert(1)</script>';

        $html = (string) $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1, 'dla' => $zlosliwy, 'qr' => $zlosliwy, 'porcje' => $zlosliwy]))
            ->assertOk()
            ->getContent();

        // Nieznane „dla” to zwykły wydruk, a wartości z adresu nie wracają do strony.
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('dla-pomocnika', $html);

        $html = (string) $this->get($this->adres($przepis, ['qr' => $zlosliwy, 'porcje' => $zlosliwy, 'x' => $zlosliwy]))->assertOk()->getContent();
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('druk-pomocnik-qr-kod', $html, 'Kod QR wymaga qr=1, a nie byle czego.');

        // Tablica zamiast tekstu też nie otwiera trybu ani nie rzuca błędu 500.
        $this->get(route('recipes.show', ['recipe' => $przepis->slug]).'?druk=1&dla[]=pomocnika')
            ->assertOk()
            ->assertDontSee('dla-pomocnika', false);
    }

    public function test_wariant_z_adresu_nie_trafia_do_wspolnego_cache_html_goscia(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $przepis = $this->przepis(['visibility' => 'public']);

        $zwykla = $this->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertTrue($zwykla->headers->hasCacheControlDirective('public'), 'Kontrola dodatnia: strona bez query jest do cache.');
        $this->assertStringNotContainsString('dla-pomocnika', $zwykla->getContent());

        foreach ([$this->adres($przepis), $this->adres($przepis, ['qr' => 1]), $this->adres($przepis, ['porcje' => 6])] as $adres) {
            $wariant = $this->get($adres)->assertOk();
            $this->assertFalse($wariant->headers->hasCacheControlDirective('public'), $adres);
            $this->assertFalse($wariant->headers->hasCacheControlDirective('s-maxage'), $adres);
            $this->assertTrue($wariant->headers->hasCacheControlDirective('no-store'), $adres);
        }

        // I po wariancie strona bez query nadal jest zwykła.
        $po = $this->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertStringNotContainsString('dla-pomocnika', $po->getContent());
    }
}
