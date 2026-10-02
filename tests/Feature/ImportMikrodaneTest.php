<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\ParserMikrodanychPrzepisu;
use App\Models\Recipe;
use Tests\TestCase;

/**
 * Odczyt przepisu z mikrodanych schema.org (#28) — lokalnie, bez modelu,
 * bez pobierania czegokolwiek. Wynik ma ten sam kształt co z JSON-LD.
 */
final class ImportMikrodaneTest extends TestCase
{
    private function parser(): ParserMikrodanychPrzepisu
    {
        return new ParserMikrodanychPrzepisu;
    }

    private function strona(string $ciało): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>Blog</title></head><body>'.$ciało.'</body></html>';
    }

    public function test_poprawny_przepis_z_mikrodanych(): void
    {
        $html = $this->strona(<<<'HTML'
            <nav>Menu</nav>
            <article itemscope itemtype="https://schema.org/Recipe">
              <h1 itemprop="name">Sernik babci &amp; wnuczki</h1>
              <img itemprop="image" src="https://obcy.example.pl/sernik.jpg">
              <p itemprop="description">Najlepszy   sernik.</p>
              <span itemprop="recipeYield">8 porcji</span>
              <meta itemprop="prepTime" content="PT30M">
              <time itemprop="cookTime" datetime="PT1H15M">1 h 15 min</time>
              <time itemprop="totalTime" datetime="PT1H45M">1 h 45 min</time>
              <ul>
                <li itemprop="recipeIngredient">1 kg twarogu</li>
                <li itemprop="recipeIngredient"> 5 jaj </li>
                <li itemprop="recipeIngredient"></li>
                <li itemprop="recipeIngredient">200 g
                    cukru</li>
              </ul>
              <div itemprop="recipeInstructions">
                <p>Utrzyj twaróg z cukrem.</p>
                <p>Dodaj jajka.<br>Piecz godzinę w 170&deg;C.</p>
              </div>
            </article>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Sernik babci & wnuczki', $przepis->tytul);
        $this->assertSame('Najlepszy sernik.', $przepis->opis);
        $this->assertSame(8.0, $przepis->porcje);
        $this->assertSame(30, $przepis->przygotowanieMinut);
        $this->assertSame(75, $przepis->gotowanieMinut);
        $this->assertSame(['1 kg twarogu', '5 jaj', '200 g cukru'], $przepis->skladniki);
        $this->assertSame(['Utrzyj twaróg z cukrem.', 'Dodaj jajka.', 'Piecz godzinę w 170°C.'], $przepis->kroki);
        $this->assertStringNotContainsString('obcy.example.pl', serialize($przepis), 'Adres zdjęcia nie wychodzi z parsera.');
    }

    public function test_kroki_howtostep_howtosection_i_stary_zapis_ingredients(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="http://schema.org/Recipe">
              <span itemprop="name">Zupa</span>
              <span itemprop="ingredients">2 marchewki</span>
              <span itemprop="ingredients">1 pietruszka</span>
              <div itemprop="recipeInstructions" itemscope itemtype="https://schema.org/HowToSection">
                <h3 itemprop="name">Wywar</h3>
                <ol>
                  <li itemprop="itemListElement" itemscope itemtype="https://schema.org/HowToStep">
                    <span itemprop="name">Krok</span><span itemprop="text">Obierz warzywa.</span>
                  </li>
                  <li itemprop="itemListElement" itemscope itemtype="https://schema.org/HowToStep">
                    <span itemprop="text">Gotuj godzinę.</span>
                  </li>
                </ol>
              </div>
              <ol itemprop="recipeInstructions">
                <li itemscope itemtype="https://schema.org/HowToStep"><p itemprop="text">Podawaj gorącą.</p></li>
              </ol>
            </div>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Zupa', $przepis->tytul);
        $this->assertSame(['2 marchewki', '1 pietruszka'], $przepis->skladniki);
        $this->assertSame(['Obierz warzywa.', 'Gotuj godzinę.', 'Podawaj gorącą.'], $przepis->kroki);
    }

    public function test_wiele_nazw_itemprop_nie_powiela_skladnika_ani_nie_zmienia_kolejnosci(): void
    {
        $html = $this->strona(<<<'HTML'
            <article itemscope itemtype="https://schema.org/Recipe">
              <h1 itemprop="name">Placek</h1>
              <li itemprop="ingredients recipeIngredient">200 g mąki</li>
              <li itemprop="recipeIngredient">1 jajko</li>
              <li itemprop="ingredients">1 jajko</li>
              <li itemprop="recipeIngredient ingredients">szczypta soli</li>
            </article>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis, 'MICRODATA_2626_SKLADNIK');
        $this->assertSame(
            ['200 g mąki', '1 jajko', '1 jajko', 'szczypta soli'],
            $przepis->skladniki,
            'MICRODATA_2626_SKLADNIK: ten sam węzeł ma być raz, a dwa osobne jednakowe teksty dwa razy w kolejności strony.',
        );
    }

    public function test_wiele_nazw_itemprop_nie_powiela_kroku_howtosection(): void
    {
        $html = $this->strona(<<<'HTML'
            <article itemscope itemtype="https://schema.org/Recipe">
              <h1 itemprop="name">Placek</h1>
              <div itemprop="recipeInstructions" itemscope itemtype="https://schema.org/HowToSection">
                <p itemprop="step itemListElement" itemscope itemtype="https://schema.org/HowToStep"><span itemprop="text">Wymieszaj.</span></p>
                <p itemprop="itemListElement" itemscope itemtype="https://schema.org/HowToStep"><span itemprop="text">Piecz.</span></p>
                <p itemprop="step" itemscope itemtype="https://schema.org/HowToStep"><span itemprop="text">Piecz.</span></p>
                <p itemprop="itemListElement step" itemscope itemtype="https://schema.org/HowToStep"><span itemprop="text">Podawaj.</span></p>
              </div>
            </article>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis, 'MICRODATA_2626_KROK');
        $this->assertSame(
            ['Wymieszaj.', 'Piecz.', 'Piecz.', 'Podawaj.'],
            $przepis->kroki,
            'MICRODATA_2626_KROK: aliasy jednego węzła nie mnożą kroku, osobne węzły zostają.',
        );
    }

    public function test_meta_content_skladnik_i_kroki_sa_odczytane_w_kolejnosci(): void
    {
        $html = $this->strona(<<<'HTML'
            <article itemscope itemtype="https://schema.org/Recipe">
              <meta itemprop="name" content="Sernik">
              <meta itemprop="recipeYield" content="1,25 porcji">
              <meta itemprop="recipeIngredient" content="200 g mąki">
              <span itemprop="recipeIngredient">1 jajko</span>
              <meta itemprop="recipeInstructions" content="Wymieszaj.">
              <div itemprop="recipeInstructions" itemscope itemtype="https://schema.org/HowToStep">
                <meta itemprop="text" content="Upiecz.">
              </div>
            </article>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis, 'MICRODATA_META_SKLADNIK');
        $this->assertSame(['200 g mąki', '1 jajko'], $przepis->skladniki, 'MICRODATA_META_SKLADNIK');
        $this->assertSame(['Wymieszaj.', 'Upiecz.'], $przepis->kroki, 'MICRODATA_META_KROK');
        $this->assertSame(1.25, $przepis->porcje);
    }

    public function test_zagniezdzone_itemscope_nie_miesza_sie_z_polami_przepisu(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="https://schema.org/Recipe">
              <div itemprop="author" itemscope itemtype="https://schema.org/Person">
                <span itemprop="name">Jan Kowalski</span>
                <span itemprop="description">Blogerka kulinarna od 2010</span>
              </div>
              <div itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
                <span itemprop="ratingValue">4.8</span><span itemprop="description">Świetne!</span>
              </div>
              <div itemprop="nutrition" itemscope itemtype="https://schema.org/NutritionInformation">
                <span itemprop="calories">300 kcal</span><span itemprop="recipeYield">99 sztuk</span>
              </div>
              <span itemprop="name">Pierogi ruskie</span>
              <span itemprop="recipeIngredient">1 kg mąki</span>
              <span itemprop="recipeInstructions">Zagnieć ciasto.</span>
            </div>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Pierogi ruskie', $przepis->tytul, 'Nazwa autora nie może zostać tytułem przepisu.');
        $this->assertNull($przepis->opis, 'Opis autora nie jest opisem przepisu.');
        $this->assertNull($przepis->porcje, 'Porcje z bloku odżywczego nie są porcjami przepisu.');
        $this->assertSame(['1 kg mąki'], $przepis->skladniki);
        $this->assertSame(['Zagnieć ciasto.'], $przepis->kroki);
        $this->assertStringNotContainsString('Kowalski', serialize($przepis));
        $this->assertStringNotContainsString('4.8', serialize($przepis));
    }

    public function test_przepis_zagniezdzony_w_innym_zakresie_jest_znaleziony(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="https://schema.org/WebPage">
              <div itemprop="mainEntity" itemscope itemtype="https://schema.org/Recipe">
                <span itemprop="name">Kompot</span>
                <span itemprop="recipeIngredient">3 jabłka</span>
              </div>
            </div>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Kompot', $przepis->tytul);
    }

    public function test_pierwszy_przepis_bez_tresci_nie_zaslania_drugiego(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name">Pusty</span></div>
            <div itemscope itemtype="https://schema.org/Recipe">
              <span itemprop="name">Pełny</span><span itemprop="recipeIngredient">sól</span>
            </div>
            HTML);

        $this->assertSame('Pełny', $this->parser()->odczytaj($html)?->tytul);
    }

    public function test_bez_tytulu_dostaje_domyslny_a_minuty_i_porcje_tylko_gdy_jednoznaczne(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="https://schema.org/Recipe">
              <span itemprop="recipeYield">4–6 porcji</span>
              <span itemprop="prepTime">pół godziny</span>
              <span itemprop="recipeIngredient">sól</span>
            </div>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame('Przepis ze strony', $przepis->tytul);
        $this->assertNull($przepis->porcje);
        $this->assertNull($przepis->przygotowanieMinut);
    }

    public function test_strona_bez_przepisu_zly_typ_pusty_i_zepsuty_html_daja_null(): void
    {
        $parser = $this->parser();

        $this->assertNull($parser->odczytaj(''));
        $this->assertNull($parser->odczytaj("  \n "));
        $this->assertNull($parser->odczytaj($this->strona('<p>Wpis o sernikach, bez danych strukturalnych.</p>')));
        $this->assertNull($parser->odczytaj($this->strona(
            '<div itemscope itemtype="https://schema.org/Article"><span itemprop="name">Artykuł</span><span itemprop="recipeIngredient">mąka</span></div>',
        )));
        $this->assertNull($parser->odczytaj($this->strona(
            '<div itemscope itemtype="https://obcy.example.pl/Recipe"><span itemprop="recipeIngredient">mąka</span></div>',
        )));
        // Przepis bez składników i bez kroków nie jest szkicem.
        $this->assertNull($parser->odczytaj($this->strona(
            '<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name">Sam tytuł</span></div>',
        )));
        // Niedomknięte znaczniki nie wywracają parsera.
        $this->assertNull($parser->odczytaj('<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name"><<<'));
    }

    public function test_bledna_deklaracja_meta_charset_nie_psuje_polskich_znakow(): void
    {
        $ciało = '<div itemscope itemtype="https://schema.org/Recipe"><h1 itemprop="name">Żurek śląski</h1>'
            .'<span itemprop="recipeIngredient">2 łyżki zakwasu</span></div>';

        foreach (['<meta charset="iso-8859-2">', '<meta http-equiv="Content-Type" content="text/html; charset=windows-1250">', ''] as $meta) {
            $przepis = $this->parser()->odczytaj('<html><head>'.$meta.'</head><body>'.$ciało.'</body></html>');

            $this->assertNotNull($przepis);
            $this->assertSame('Żurek śląski', $przepis->tytul);
            $this->assertSame(['2 łyżki zakwasu'], $przepis->skladniki);
        }
    }

    public function test_zle_utf8_daje_null_a_nie_wyjatek(): void
    {
        $html = $this->strona('<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name">Sernik</span><span itemprop="recipeIngredient">twar'."\xC3\x28".'g</span></div>');

        $this->assertNull($this->parser()->odczytaj($html));
    }

    public function test_za_duza_strona_daje_null(): void
    {
        $html = $this->strona('<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="recipeIngredient">sól</span></div>'
            .str_repeat(' ', ParserMikrodanychPrzepisu::MAKS_BAJTOW));

        $this->assertNull($this->parser()->odczytaj($html));
    }

    public function test_liczba_pozycji_i_wezlow_jest_ograniczona(): void
    {
        $skladniki = str_repeat('<li itemprop="recipeIngredient">jajko</li>', 5000);
        $html = $this->strona('<ul itemscope itemtype="https://schema.org/Recipe">'.$skladniki.'</ul>');

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertLessThanOrEqual(Recipe::MAX_INGREDIENTS, count($przepis->skladniki));
        $this->assertNotEmpty($przepis->skladniki);

        // Bardzo głębokie zagnieżdżenie nie wywraca parsera.
        $glebokie = str_repeat('<div>', 3000).'<span itemprop="recipeIngredient">sól</span>'.str_repeat('</div>', 3000);
        $this->parser()->odczytaj($this->strona('<div itemscope itemtype="https://schema.org/Recipe">'.$glebokie.'</div>'));
        $this->addToAssertionCount(1);
    }

    public function test_nic_nie_jest_pobierane_ani_zapisywane_adresy_sa_ignorowane(): void
    {
        $html = $this->strona(<<<'HTML'
            <div itemscope itemtype="https://schema.org/Recipe" itemref="obce">
              <link itemprop="image" href="https://obcy.example.pl/a.jpg">
              <a itemprop="url" href="https://obcy.example.pl/b">link</a>
              <span itemprop="name">Placki</span>
              <span itemprop="recipeIngredient">ziemniaki</span>
            </div>
            <script>var x = 'itemprop="recipeIngredient"';</script>
            HTML);

        $przepis = $this->parser()->odczytaj($html);

        $this->assertNotNull($przepis);
        $this->assertSame(['ziemniaki'], $przepis->skladniki);
        $this->assertStringNotContainsString('obcy.example.pl', serialize($przepis));
    }

    public function test_json_ld_ma_pierwszenstwo_a_mikrodane_gdy_go_brak(): void
    {
        $mikrodane = '<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name">Z mikrodanych</span>'
            .'<span itemprop="recipeIngredient">mąka</span></div>';
        $jsonLd = '<script type="application/ld+json">'.json_encode([
            '@type' => 'Recipe', 'name' => 'Z JSON-LD', 'recipeIngredient' => ['cukier'],
        ]).'</script>';

        $oba = $this->strona($jsonLd.$mikrodane);
        $tylkoMikrodane = $this->strona($mikrodane);

        // Kolejność wybiera OdczytajPrzepisZAdresu (JSON-LD, potem mikrodane) — tu sprawdzamy oba parsery na tej samej stronie.
        $this->assertSame('Z JSON-LD', (new ParserJsonLdPrzepisu)->odczytaj($oba)?->tytul);
        $this->assertSame('Z mikrodanych', $this->parser()->odczytaj($oba)?->tytul);
        $this->assertNull((new ParserJsonLdPrzepisu)->odczytaj($tylkoMikrodane));
        $this->assertSame('Z mikrodanych', $this->parser()->odczytaj($tylkoMikrodane)?->tytul);
    }
}
