<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strona przepisu używa KOLUMNY SZYNY, zamiast zostawiać ją pustą (issue #365).
 *
 * ZGŁOSZENIE WŁAŚCICIELA, ZE ZRZUTU: „w prawej kolumnie która jest pusta,
 * można dać to np" — z ręką na bloku „Ugotowałem / Zapisuję / Gotuję /
 * Podziel się / Skąd ten przepis".
 *
 * ZMIERZONE PRZED POPRAWKĄ (Chromium, dane demo):
 *   * gość, okno 1920 px: cała strona zwinięta do 768 px — bo bez szyny
 *     bierze `--container-strona-solo`;
 *   * zalogowany, okno 1920 px: rama 1424 px, kolumna czytania 720 px,
 *     TRZECIA KOLUMNA PUSTA; „Ugotowałem" przy `x = 981`, `y = 538`.
 * PO: gość 1152 px, zalogowany bez zmian w ramie, treść 1104 px,
 * „Ugotowałem" przy `x = 1301`, `y = 316`.
 *
 * DLACZEGO TEN TEST NIE SPRAWDZA `<x-slot:rail>`
 * Bo ten ekran świadomie go NIE używa i to jest sedno poprawki. Slot renderuje
 * się w kodzie ZA całym `<main>` — czyli za składnikami, krokami
 * i komentarzami. Na telefonie kolumn nie ma i to kolejność w kodzie decyduje,
 * co człowiek czyta najpierw; główna akcja produktu leżała już raz pod krokami
 * i została stamtąd wyciągnięta (`EkranPrzepisuWedlugKituTest`). Panel zostaje
 * więc w `<main>`, a kolumnę szyny zajmuje siatka samego ekranu.
 *
 * Test pilnuje więc TRZECH rzeczy naraz — każda bez pozostałych nic nie
 * dowodzi:
 *   1. ekran naprawdę prosi o szerszą ramę (klasa na `.app-body`, a u gościa
 *      także na `<body>`, bo belka i stopka biorą szerokość stamtąd);
 *   2. panel dalej stoi W KODZIE przed składnikami i jest bezpośrednim
 *      dzieckiem siatki (inaczej `grid-column` na nim nic nie robi);
 *   3. arkusz naprawdę daje tej siatce dwie kolumny — i kolumna TEKSTU
 *      zostaje przy `--container-content`, bo rośnie rama i to, co obok,
 *      a nie długość wiersza.
 */
class StronaPrzepisuUzywaKolumnySzynyTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        $autor = $this->user('autorszyny');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Rosół babci Zofii',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'servings' => 4,
            'prep_minutes' => 20,
            'cook_minutes' => 100,
            'difficulty' => 'easy',
        ]);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'ingredient_text' => 'pół kurczaka, najlepiej zagrodowego',
        ]);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Zalej mięso zimną wodą i gotuj bez pokrywki.',
        ]);

        return $przepis;
    }

    private function arkusz(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    // --- HTML ------------------------------------------------------------

    public function test_ekran_przepisu_prosi_o_kolumne_szyny(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString(
            'app-body-tresc-z-szyna',
            $html,
            'Rama strony przepisu nie niesie klasy, która oddaje jej kolumnę szyny — '
            .'kolumna zostaje pusta, a u gościa cała strona zwija się do 768 px.',
        );

        $this->assertStringContainsString(
            'przepis-uklad',
            $html,
            'Brak siatki `.przepis-uklad` na `<article>` — panel nie ma czym przejść do drugiej kolumny.',
        );
    }

    /**
     * U GOŚCIA LICZY SIĘ TAKŻE BELKA I STOPKA.
     *
     * Szerokość belki i stopki gościa bierze się z klasy na `<body>`
     * (`uklad-solo-z-szyna`), a nie z siatki. Bez niej logotyp stanąłby 192 px
     * na lewo od pierwszego słowa tytułu — to jest ten sam rozjazd, który
     * naprawiał blok „BELKA I STOPKA STOJĄ W TEJ SAMEJ SIATCE CO TREŚĆ".
     */
    public function test_goscia_belka_i_stopka_ida_za_trescia(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<body class="[^"]*uklad-solo-z-szyna/',
            $html,
            'Gość dostaje szerszą treść, ale belka i stopka zostają przy 768 px.',
        );

        // KONTROLA UJEMNA W DRUGĄ STRONĘ: ekran BEZ szyny tej klasy nie ma.
        // Bez tej połowy testu przeszłaby zmiana „daj wszystkim gościom
        // szerszą belkę", która rozjechałaby logowanie i rejestrację.
        $logowanie = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'uklad-solo-z-szyna',
            $logowanie,
            'Ekran logowania — bez szyny i bez szerokiej treści — dostał szerszą belkę.',
        );
    }

    /**
     * PANEL JEST BEZPOŚREDNIM DZIECKIEM SIATKI I STOI PRZED SKŁADNIKAMI.
     *
     * Dwie rzeczy w jednym sprawdzeniu, bo są dwiema stronami tej samej
     * decyzji: `grid-column` działa wyłącznie na BEZPOŚREDNIM dziecku siatki
     * (gdyby panel wrócił do środka `.przepis-hero`, reguła z arkusza nie
     * dotyczyłaby go i cicho przestałby wchodzić w kolumnę szyny), a jego
     * miejsce w kodzie jest tym, co widzi telefon i czytnik ekranu.
     */
    public function test_panel_stoi_w_siatce_i_w_kodzie_przed_skladnikami(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->actingAs($this->user('czytelnik'))
            ->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $panel = $xpath->query(
            '//article[contains(concat(" ", normalize-space(@class), " "), " przepis-uklad ")]'
            .'/div[contains(concat(" ", normalize-space(@class), " "), " przepis-panel ")]',
        )->item(0);

        $this->assertNotNull(
            $panel,
            'Panel akcji nie jest bezpośrednim dzieckiem `.przepis-uklad` — `grid-column` z arkusza '
            .'go nie dotyczy i zostaje w kolumnie czytania, choć obok stoi pusta kolumna szyny.',
        );

        $ugotowalem = strpos($html, '>Ugotowałem<');
        $skladniki = strpos($html, '>Składniki<');

        $this->assertNotFalse($ugotowalem, 'Na ekranie przepisu nie ma przycisku „Ugotowałem".');
        $this->assertNotFalse($skladniki, 'Na ekranie przepisu nie ma nagłówka „Składniki".');
        $this->assertLessThan(
            $skladniki,
            $ugotowalem,
            'Główna akcja stoi w kodzie ZA składnikami. Na telefonie kolumn nie ma, więc znaczy to, '
            .'że „Ugotowałem" widzi tylko ten, kto przewinie cały przepis.',
        );
    }

    // --- ARKUSZ ----------------------------------------------------------

    public function test_nowy_arkusz_przenosi_dwie_kolumny_do_hero(): void
    {
        // Geometrię po buildzie mierzy Chromium; tutaj pilnujemy obecności portu.
        $css = (string) file_get_contents(resource_path('css/marka-przepis.css'));
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $css);
        $this->assertMatchesRegularExpression('/\.marka-przepis\s*\{[^}]*display:\s*block;/s', $css);
        $this->assertMatchesRegularExpression('/\.marka-przepis\s*>\s*\.przepis-panel\s*\{[^}]*grid-column:\s*auto;/s', $css);
    }

    /**
     * PUNKT 3 ZGŁOSZENIA: ROŚNIE RAMA, NIE DŁUGOŚĆ WIERSZA.
     *
     * To jest kontrola ujemna do całej tej zmiany. Oddanie ekranowi drugiej
     * kolumny kusi, żeby przy okazji „wykorzystać miejsce" i puścić tekst na
     * całą szerokość — a wiersz na 1400 px jest nieczytelny niezależnie od
     * wieku czytelnika (docs/UX_50_PLUS.md: 55–75 znaków).
     */
    public function test_kolumna_tekstu_zostaje_przy_45rem(): void
    {
        $css = $this->arkusz();

        $this->assertMatchesRegularExpression(
            '/\.app-main\s*\{[^}]*max-width:\s*var\(--container-content\)\s*;/s',
            $css,
            'Kolumna czytania przestała mieć sufit `--container-content` — tekst rozlewa się '
            .'na całą ramę.',
        );

        $this->assertMatchesRegularExpression(
            '/\.przepis-uklad\s*\{[^}]*minmax\(\s*0\s*,\s*var\(--container-content\)\s*\)/s',
            $css,
            'Pierwsza kolumna ekranu przepisu nie jest ograniczona do `--container-content`.',
        );
    }
}
