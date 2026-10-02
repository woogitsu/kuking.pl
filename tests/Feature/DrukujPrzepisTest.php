<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Drukuj przepis” (#765): widoczny przycisk, który bez skryptu prowadzi
 * do instrukcji zamiast milczeć (D-053), i arkusz druku, w którym żadne
 * pismo nie schodzi poniżej 12 pt.
 *
 * Ułożenie kartki w prawdziwym `@media print` mierzy
 * `node scripts/wydruk-przepisu.mjs` (każdy element z tekstem ≥ 16 px CSS).
 */
class DrukujPrzepisTest extends TestCase
{
    use RefreshDatabase;

    private const ARKUSZ = 'resources/css/wydruk-przepisu.css';

    public function test_przepis_ma_widoczny_przycisk_drukuj_ktory_bez_skryptu_prowadzi_do_instrukcji(): void
    {
        $recipe = Recipe::factory()->create();
        $cel = route('recipes.show', ['recipe' => $recipe->slug, 'druk' => 1]).'#jak-wydrukowac';

        $this->get(route('recipes.show', $recipe->slug))
            ->assertOk()
            ->assertSee('<a class="btn btn-secondary" href="'.e($cel).'" rel="nofollow" data-drukuj-przepis>Drukuj przepis</a>', false)
            // Instrukcja tylko po kliknięciu — nie na każdej stronie przepisu.
            ->assertDontSee('id="jak-wydrukowac"', false);
    }

    public function test_glowny_link_drukowania_przenosi_wybrane_porcje_i_otwiera_przeliczona_kartke(): void
    {
        $recipe = Recipe::factory()->create(['servings' => 4]);
        RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'ingredient_text' => '400 g mąki']);

        $strona = $this->get(route('recipes.show', ['recipe' => $recipe->slug, 'porcje' => 2]))->assertOk();
        $href = $this->adresGlownyDrukowania((string) $strona->getContent());
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parametry);
        $this->assertSame('2', $parametry['porcje'] ?? null, 'DRUK_PORCJE_HREF: link nie zachował zaakceptowanych porcji.');
        $this->assertSame('1', $parametry['druk'] ?? null);

        $kartka = $this->get(explode('#', $href, 2)[0])->assertOk();
        $kartka->assertSee('Jak wydrukować ten przepis:');
        $this->assertSame('200 g mąki', $this->pierwszySkladnik((string) $kartka->getContent()));
    }

    public function test_link_drukowania_nie_przenosi_blednych_ani_domyslnych_porcji(): void
    {
        $recipe = Recipe::factory()->create(['servings' => 4]);
        RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'ingredient_text' => '400 g mąki']);

        foreach ([null, '0', 'abc'] as $wartosc) {
            $adres = route('recipes.show', array_filter([
                'recipe' => $recipe->slug, 'porcje' => $wartosc,
            ], static fn ($value) => $value !== null));
            $href = $this->adresGlownyDrukowania((string) $this->get($adres)->assertOk()->getContent());
            parse_str((string) parse_url($href, PHP_URL_QUERY), $parametry);
            $this->assertArrayNotHasKey('porcje', $parametry, 'Odrzucone lub domyślne porcje nie powinny przechodzić do druku.');
            $this->assertSame('400 g mąki', $this->pierwszySkladnik(
                (string) $this->get(explode('#', $href, 2)[0])->assertOk()->getContent(),
            ));
        }
    }

    private function adresGlownyDrukowania(string $html): string
    {
        $xpath = $this->xpath($html);
        $link = $xpath->query('//a[@data-drukuj-przepis]');
        $this->assertInstanceOf(DOMNodeList::class, $link);
        $this->assertSame(1, $link->length, 'Na stronie przepisu powinien być jeden główny link drukowania.');
        $element = $link->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element->getAttribute('href');
    }

    private function pierwszySkladnik(string $html): string
    {
        $skladnik = $this->xpath($html)->query('//ul[@class="ingredient-list"]/li[1]');
        $this->assertInstanceOf(DOMNodeList::class, $skladnik);
        $this->assertSame(1, $skladnik->length, 'Na kartce brakuje pierwszego składnika.');
        $element = $skladnik->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_gosc_tez_widzi_przycisk(): void
    {
        $recipe = Recipe::factory()->create();

        $this->assertGuest();
        $this->get(route('recipes.show', $recipe->slug))->assertOk()->assertSee('Drukuj przepis');
    }

    public function test_bez_skryptu_link_pokazuje_co_zrobic(): void
    {
        $recipe = Recipe::factory()->create();

        $this->get(route('recipes.show', ['recipe' => $recipe->slug, 'druk' => 1]))
            ->assertOk()
            ->assertSee('id="jak-wydrukowac"', false)
            ->assertSee('Jak wydrukować ten przepis:')
            ->assertSee('<kbd>Ctrl</kbd> i <kbd>P</kbd>', false)
            ->assertSee('wybierz „Drukuj”', false);
    }

    public function test_przycisk_nie_omija_policy(): void
    {
        $recipe = Recipe::factory()->create(['visibility' => 'followers']);

        $odpowiedz = $this->get(route('recipes.show', ['recipe' => $recipe->slug, 'druk' => 1]));

        $this->assertNotSame(200, $odpowiedz->getStatusCode());
        $odpowiedz->assertDontSee('Jak wydrukować ten przepis');
    }

    public function test_skrypt_drukowania_jest_wczytywany(): void
    {
        $this->assertStringContainsString("import './drukuj-przepis.js';", (string) file_get_contents(base_path('resources/js/app.js')));
        $this->assertStringContainsString('window.print()', (string) file_get_contents(base_path('resources/js/drukuj-przepis.js')));
    }

    public function test_arkusz_druku_ma_prog_12_pt_i_nie_schodzi_ponizej(): void
    {
        $druk = self::blokDruku((string) file_get_contents(base_path(self::ARKUSZ)));

        $this->assertStringContainsString(
            'font-size: max(calc(12pt * var(--druk-skala)), 1em);',
            $druk,
            'Arkusz druku nie ma progu 12 pt dla całego tekstu przepisu.',
        );

        $rozmiary = self::rozmiaryWPunktach($druk);
        $this->assertNotEmpty($rozmiary, 'Kontrola: w bloku druku nie znaleziono żadnego rozmiaru pisma.');
        $this->assertGreaterThanOrEqual(12.0, min($rozmiary), 'Reguła druku ustawia pismo poniżej 12 pt.');

        // Chowa przycisk i instrukcję — na papierze nie da się ich kliknąć.
        $this->assertStringContainsString('.druk-podpowiedz', $druk);
        $this->assertStringContainsString('main :is(.btn, button, form)', $druk);
    }

    public function test_parser_arkusza_lapie_za_male_pismo(): void
    {
        // Kontrola dodatnia parsera: bez niej test wyżej przeszedłby na arkuszu,
        // z którego regex niczego nie wyczytał.
        $this->assertSame([12.0, 9.0, 10.5], self::rozmiaryWPunktach(
            'a { font-size: calc(12pt * var(--druk-skala)); } b { font-size: 9pt; } c { font-size: 14px; } d { font-size: 1em; }',
        ));
    }

    private static function blokDruku(string $css): string
    {
        $start = strpos($css, '@media print');
        self::assertNotFalse($start, 'Arkusz nie ma bloku @media print.');

        return substr($css, $start);
    }

    /** @return list<float> */
    private static function rozmiaryWPunktach(string $css): array
    {
        preg_match_all('/font-size:\s*([^;]+);/', $css, $m);
        $wynik = [];
        foreach ($m[1] as $wartosc) {
            if (preg_match('/^(?:max\()?(?:calc\()?\s*([\d.]+)(pt|px)\b/', trim($wartosc), $l) === 1) {
                $wynik[] = $l[2] === 'px' ? (float) $l[1] * 0.75 : (float) $l[1];
            }
        }

        return $wynik;
    }
}
