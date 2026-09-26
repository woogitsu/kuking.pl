<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ParserTekstuPrzepisu;
use App\Domain\Import\TrybFragmentow;
use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\RobotsTxt;
use App\Domain\Import\Url\TekstStrony;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lokalne odczyty przepisu (D-300): JSON-LD `Recipe`, tekst PDF-a,
 * `robots.txt` i składanie szkicu z granic fragmentów. Żaden z nich nie
 * woła modelu — to są ścieżki bez kosztu.
 */
final class ImportParseryTest extends TestCase
{
    // ---------------------------------------------------------------
    // robots.txt
    // ---------------------------------------------------------------

    public function test_robots_grupa_naszego_tokenu_wygrywa_z_gwiazdka(): void
    {
        $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: /\n\nUser-agent: KukingImport\nAllow: /przepisy/\nDisallow: /\n");

        $this->assertTrue($robots->wolno('/przepisy/sernik'));
        $this->assertFalse($robots->wolno('/konto'));
    }

    public function test_robots_najdluzsze_dopasowanie_i_remis_dla_allow(): void
    {
        $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: /przepisy/\nAllow: /przepisy/publiczne/\nDisallow: /*.php$\n");

        $this->assertFalse($robots->wolno('/przepisy/sernik'));
        $this->assertTrue($robots->wolno('/przepisy/publiczne/sernik'));
        $this->assertFalse($robots->wolno('/index.php'));
        $this->assertTrue($robots->wolno('/index.php?x=1'));
        $this->assertTrue($robots->wolno('/'));
    }

    public function test_robots_pusty_disallow_niczego_nie_zabrania_a_komentarze_sa_pomijane(): void
    {
        $robots = RobotsTxt::zTresci("# komentarz\nUser-agent: *\nDisallow: # nic\n");

        $this->assertTrue($robots->wolno('/cokolwiek'));
    }

    public function test_robots_wspolna_grupa_dla_kilku_agentow(): void
    {
        $robots = RobotsTxt::zTresci("User-agent: Googlebot\nUser-agent: KukingImport\nDisallow: /tajne\n");

        $this->assertFalse($robots->wolno('/tajne/sernik'));
        $this->assertTrue($robots->wolno('/sernik'));
    }

    // ---------------------------------------------------------------
    // JSON-LD Recipe
    // ---------------------------------------------------------------

    private function stronaZJsonLd(array $dane): string
    {
        return '<html><head><script type="application/ld+json">'
            .json_encode($dane, JSON_UNESCAPED_UNICODE)
            .'</script></head><body>Treść</body></html>';
    }

    public function test_json_ld_w_grafie_z_krokami_howto_i_sekcjami(): void
    {
        $html = $this->stronaZJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebPage', 'name' => 'Strona'],
                [
                    '@type' => ['Recipe', 'NewsArticle'],
                    'name' => 'Sernik babci &amp; wnuczki',
                    'description' => '<p>Najlepszy sernik.</p>',
                    'image' => ['https://obcy.example.pl/zdjecie.jpg'],
                    'recipeYield' => ['8 porcji', '1 blacha'],
                    'prepTime' => 'PT30M',
                    'cookTime' => 'PT1H15M',
                    'recipeIngredient' => ['1 kg twarogu', ' 5 jaj ', '', '200 g cukru'],
                    'recipeInstructions' => [
                        ['@type' => 'HowToSection', 'name' => 'Masa', 'itemListElement' => [
                            ['@type' => 'HowToStep', 'text' => 'Utrzyj twaróg z cukrem.'],
                            ['@type' => 'HowToStep', 'text' => 'Dodaj jajka.'],
                        ]],
                        ['@type' => 'HowToStep', 'text' => 'Piecz godzinę w 170&deg;C.'],
                    ],
                ],
            ],
        ]);

        $przepis = (new ParserJsonLdPrzepisu)->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Sernik babci & wnuczki', $przepis->tytul);
        $this->assertSame('Najlepszy sernik.', $przepis->opis);
        $this->assertSame(8.0, $przepis->porcje);
        $this->assertSame(30, $przepis->przygotowanieMinut);
        $this->assertSame(75, $przepis->gotowanieMinut);
        $this->assertSame(['1 kg twarogu', '5 jaj', '200 g cukru'], $przepis->skladniki);
        $this->assertSame(['Utrzyj twaróg z cukrem.', 'Dodaj jajka.', 'Piecz godzinę w 170°C.'], $przepis->kroki);
    }

    public function test_json_ld_nie_przenosi_adresu_zdjecia_ani_autora_nigdzie(): void
    {
        $html = $this->stronaZJsonLd([
            '@type' => 'Recipe',
            'name' => 'Pierogi',
            'image' => 'https://obcy.example.pl/zdjecie.jpg',
            'author' => ['@type' => 'Person', 'name' => 'Jan Obcy'],
            'recipeIngredient' => ['mąka'],
            'recipeInstructions' => 'Zagnieć ciasto.',
        ]);

        $przepis = (new ParserJsonLdPrzepisu)->odczytaj($html);

        $this->assertNotNull($przepis);
        $zrzut = serialize($przepis);
        $this->assertStringNotContainsString('zdjecie.jpg', $zrzut);
        $this->assertStringNotContainsString('Jan Obcy', $zrzut);
    }

    public function test_json_ld_z_instrukcja_jako_html_i_przedzialem_porcji(): void
    {
        $html = $this->stronaZJsonLd([
            '@type' => 'http://schema.org/Recipe',
            'name' => 'Żurek',
            'recipeYield' => '4-6',
            'prepTime' => '20 minut',
            'recipeIngredient' => "zakwas\nkiełbasa",
            'recipeInstructions' => '<ol><li>Zagotuj wodę.</li><li>Wlej zakwas.</li></ol>',
        ]);

        $przepis = (new ParserJsonLdPrzepisu)->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertNull($przepis->porcje);
        $this->assertNull($przepis->przygotowanieMinut);
        $this->assertSame(['zakwas', 'kiełbasa'], $przepis->skladniki);
        $this->assertSame(['Zagotuj wodę.', 'Wlej zakwas.'], $przepis->kroki);
    }

    public function test_strona_bez_recipe_albo_z_zepsutym_json_ld_daje_null(): void
    {
        $parser = new ParserJsonLdPrzepisu;

        $this->assertNull($parser->odczytaj('<html><body>Sernik</body></html>'));
        $this->assertNull($parser->odczytaj('<script type="application/ld+json">{zepsuty</script>'));
        $this->assertNull($parser->odczytaj($this->stronaZJsonLd(['@type' => 'Article', 'name' => 'Nie przepis'])));
        $this->assertNull($parser->odczytaj($this->stronaZJsonLd(['@type' => 'Recipe', 'name' => 'Pusty przepis'])));
    }

    // ---------------------------------------------------------------
    // Tekst strony dla trybu fragmentów
    // ---------------------------------------------------------------

    public function test_tekst_strony_bez_skryptow_nawigacji_i_komentarzy(): void
    {
        $wiersze = TekstStrony::wiersze(
            '<html><head><script>var tajne = 1;</script><style>p{}</style></head><body>'
            .'<nav>Menu Start Kontakt</nav><h1>Bigos</h1><p>Kapusta kiszona</p><!-- ukryte -->'
            .'<div class="comments-area"><p>Pyszne! — Ania</p></div><footer>Stopka</footer></body></html>',
        );

        $this->assertSame(['Bigos', 'Kapusta kiszona'], $wiersze);
    }

    // ---------------------------------------------------------------
    // Tryb fragmentów
    // ---------------------------------------------------------------

    public function test_fragmenty_skladaja_szkic_dokladnie_z_wierszy_oryginalu(): void
    {
        $wiersze = ['Menu', 'Bigos myśliwski', '1 kg kapusty', '300 g kiełbasy', 'Pokrój kapustę.', 'Duś trzy godziny.', 'Udostępnij'];

        $przepis = (new TrybFragmentow)->zloz($wiersze, [
            ['do' => 1, 'etykieta' => 'pomin'],
            ['do' => 2, 'etykieta' => 'tytul'],
            ['do' => 4, 'etykieta' => 'skladnik'],
            ['do' => 5, 'etykieta' => 'krok'],
            ['do' => 6, 'etykieta' => 'krok'],
            ['do' => 7, 'etykieta' => 'pomin'],
        ]);

        $this->assertNotNull($przepis);
        $this->assertSame('Bigos myśliwski', $przepis->tytul);
        $this->assertSame(['1 kg kapusty', '300 g kiełbasy'], $przepis->skladniki);
        $this->assertSame(['Pokrój kapustę.', 'Duś trzy godziny.'], $przepis->kroki);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function odpowiedziOdrzucane(): array
    {
        return [
            'niepełne pokrycie' => [[['do' => 2, 'etykieta' => 'krok']]],
            'granica za końcem' => [[['do' => 3, 'etykieta' => 'krok'], ['do' => 9, 'etykieta' => 'krok']]],
            'granice nie rosną' => [[['do' => 2, 'etykieta' => 'krok'], ['do' => 2, 'etykieta' => 'krok'], ['do' => 3, 'etykieta' => 'krok']]],
            'obca etykieta' => [[['do' => 3, 'etykieta' => 'reklama']]],
            'dopisany tekst' => [[['do' => 3, 'etykieta' => 'krok', 'tekst' => 'Dodaj szklankę cukru.']]],
            'numer jako tekst' => [[['do' => '3', 'etykieta' => 'krok']]],
            'nie lista' => [['a' => ['do' => 3, 'etykieta' => 'krok']]],
            'pusta' => [[]],
            'tekst zamiast tablicy' => ['Oto przepis: ...'],
            'same pominięcia' => [[['do' => 3, 'etykieta' => 'pomin']]],
        ];
    }

    #[DataProvider('odpowiedziOdrzucane')]
    public function test_odpowiedz_modelu_bez_pelnego_pokrycia_albo_z_obcym_polem_jest_odrzucona(mixed $odpowiedz): void
    {
        $this->assertNull((new TrybFragmentow)->zloz(['Pierwszy', 'Drugi', 'Trzeci'], $odpowiedz));
    }

    // ---------------------------------------------------------------
    // Tekst z PDF — lokalnie, bez modelu
    // ---------------------------------------------------------------

    public function test_tekst_z_naglowkami_dzieli_sie_na_skladniki_i_kroki(): void
    {
        $przepis = (new ParserTekstuPrzepisu)->odczytaj(
            "Sernik babci\n\nNajlepszy w rodzinie.\n\nSkładniki:\n- 1 kg twarogu\n• 5 jaj\n\n"
            ."Sposób przygotowania\n1. Utrzyj twaróg.\n2. Dodaj jajka\ni wymieszaj.\n\nPiecz godzinę.\n",
        );

        $this->assertNotNull($przepis);
        $this->assertSame('Sernik babci', $przepis->tytul);
        $this->assertSame('Najlepszy w rodzinie.', $przepis->opis);
        $this->assertSame(['1 kg twarogu', '5 jaj'], $przepis->skladniki);
        $this->assertSame(['Utrzyj twaróg.', 'Dodaj jajka i wymieszaj.', 'Piecz godzinę.'], $przepis->kroki);
    }

    public function test_tekst_bez_naglowkow_trafia_w_calosci_do_krokow_i_nic_nie_ginie(): void
    {
        $przepis = (new ParserTekstuPrzepisu)->odczytaj("Kompot\n\nWeź śliwki i jabłka.\n\nZagotuj z wodą.\n");

        $this->assertNotNull($przepis);
        $this->assertSame('Kompot', $przepis->tytul);
        $this->assertSame([], $przepis->skladniki);
        $this->assertSame(['Weź śliwki i jabłka.', 'Zagotuj z wodą.'], $przepis->kroki);
    }
}
