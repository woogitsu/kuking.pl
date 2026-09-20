<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rytm pionowy strony przepisu — zgłoszenie właściciela z 11 września:
 * „a propos przepisw, trzeba naprawić te odstępy między tekstami w przepisach".
 *
 * CO BYŁO ZMIERZONE (Chromium, pełny przepis, okno 1512 px, 400 px
 * i 1512 px przy czcionce przeglądarki 200 %): PIĘĆ par bloków tekstu
 * stało na zero pikseli — tytuł i wiersz autora, zdjęcie i plakietki,
 * „Skąd ten przepis" i pierwszy akapit, „Składniki" i lista, „Przygotowanie"
 * i lista kroków. Odstęp nagłówka od zdjęcia był przy tym na desktopie inny
 * (36 px) niż na telefonie (20 px), bo od 80rem `<article>` jest siatką,
 * a marginesy elementów siatki się nie zlewają.
 *
 * CZEGO TEN TEST PILNUJE
 * Nie wyglądu — od tego są zrzuty i `scripts/dostepnosc.mjs`. Pilnuje tego,
 * co da się sprawdzić w sekundę i co przy następnym przestylowaniu zniknie
 * najciszej:
 *
 *  1. KAŻDA PARA MA ZADEKLAROWANY ODSTĘP. Dla każdego miejsca, w którym
 *     bloki tekstu stały zlepione, w arkuszu stoi reguła z odstępem.
 *  2. ODSTĘP JEST TOKENEM, NIE LICZBĄ Z PALCA. `var(--spacing-*)`, bo to
 *     odstęp typograficzny — ma rosnąć razem z pismem przy czcionce
 *     przeglądarki 200 % (druga strona D-082/D-107).
 *  3. SZABLON NIE ODBIERA ARKUSZOWI GŁOSU. Klasy `mb-*` / `mt-0` leżą
 *     w warstwie `utilities`, która w Tailwindzie 4 stoi PO `components` —
 *     więc dopóki wisiały na nagłówku przepisu, żadna reguła z arkusza nie
 *     mogła ich poprawić. Test sprawdza wyrenderowany HTML, nie plik Blade:
 *     liczy się to, co dostaje przeglądarka.
 *
 * KOMENTARZE WYCINAMY, ZANIM COKOLWIEK DOPASUJEMY — nauka
 * z `MinimalnyRozmiarTekstuTest`: wzorzec „selektor, potem `{…}`" nie
 * odróżnia reguły od nazwy klasy WYMIENIONEJ W KOMENTARZU, a ten blok CSS
 * ma nad sobą kilkadziesiąt linii komentarza, w którym każdy z tych
 * selektorów pada z nazwy. Bez wycięcia komentarzy test czytałby ciało
 * cudzej reguły — raz oblewając bez powodu, raz przepuszczając zero.
 */
class RytmPionowyStronyPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Para bloków tekstu → reguła, która ma między nie wstawić odstęp.
     *
     * Klucz jest opisem po polsku, bo to on pokazuje się w komunikacie
     * błędu i to on mówi, CO się zlepiło na ekranie.
     *
     * @var array<string, array{selektor: string, wlasciwosc: string}>
     */
    private const ODSTEPY = [
        'kolejne bloki artykułu (nagłówek → zdjęcie → panel → plakietki → wstęp → składniki → komentarze)' => [
            'selektor' => '.przepis-uklad > * + *',
            'wlasciwosc' => 'margin-top',
        ],
        'okruszki i linia atrybucji' => [
            'selektor' => '.przepis-uklad > header .okruchy',
            'wlasciwosc' => 'margin-bottom',
        ],
        'tytuł przepisu i wiersz autora' => [
            'selektor' => '.przepis-uklad > header h1',
            'wlasciwosc' => 'margin-bottom',
        ],
        'nagłówek „Skąd ten przepis" i pierwszy akapit' => [
            'selektor' => '.recipe-story h2',
            'wlasciwosc' => 'margin-bottom',
        ],
        'dwa akapity w „Skąd ten przepis" (po kim przepis i notatka autora)' => [
            'selektor' => '.recipe-story > p',
            'wlasciwosc' => 'margin-bottom',
        ],
        'nagłówki „Składniki" i „Przygotowanie" a ich listy' => [
            'selektor' => '.przepis-siatka h2',
            'wlasciwosc' => 'margin-bottom',
        ],
    ];

    /** Treść arkusza BEZ komentarzy — patrz opis klasy. */
    private function css(string $plik): string
    {
        $tresc = (string) file_get_contents(resource_path('css/'.$plik));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Deklaracje reguły o DOKŁADNIE takiej liście selektorów.
     *
     * Porównujemy całą listę po normalizacji białych znaków, a nie szukamy
     * podciągu: `.recipe-story h2` jest podciągiem `.recipe-story h2 span`,
     * a `.przepis-uklad > *` podciągiem `.przepis-uklad > * + *`. Dopasowanie
     * „byle gdzie" czytałoby wtedy ciało sąsiedniej reguły.
     */
    private function deklaracje(string $css, string $selektor): ?string
    {
        // Bez kotwicy na poprzedniej klamrze: `preg_match_all` zjada `}`
        // razem z dopasowaniem, więc wzorzec wymagający `}` PRZED selektorem
        // łapał co drugą regułę (zmierzone: 200 zamiast 407).
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $reguly, PREG_SET_ORDER);

        $szukany = $this->znormalizuj($selektor);

        foreach ($reguly as $regula) {
            foreach (explode(',', $regula[1]) as $jedenSelektor) {
                if ($this->znormalizuj($jedenSelektor) === $szukany) {
                    return $regula[2];
                }
            }
        }

        return null;
    }

    private function znormalizuj(string $selektor): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($selektor));
    }

    public function test_kazda_para_blokow_tekstu_ma_zadeklarowany_odstep(): void
    {
        $css = $this->css('app.css');

        foreach (self::ODSTEPY as $para => $gdzie) {
            $deklaracje = $this->deklaracje($css, $gdzie['selektor']);

            $this->assertNotNull(
                $deklaracje,
                "Zniknęła reguła `{$gdzie['selektor']}` z resources/css/app.css. ".
                "Bez niej na stronie przepisu zlepiają się: {$para}. ".
                'Jeśli selektor zmienił nazwę, popraw go w tym teście — nie usuwaj wiersza.',
            );

            $this->assertSame(
                1,
                preg_match(
                    '/(?<![\w-])'.preg_quote($gdzie['wlasciwosc'], '/').'\s*:\s*var\((--spacing-\d+)\)/',
                    $deklaracje,
                    $trafienie,
                ),
                "Reguła `{$gdzie['selektor']}` musi ustawiać {$gdzie['wlasciwosc']} ".
                'na token `var(--spacing-N)`. Zlepia się bez tego: '.$para.'. '.
                'Token, a nie piksele: ten odstęp oddziela BLOKI TEKSTU, więc ma rosnąć '.
                'razem z pismem przy czcionce przeglądarki 200 % (druga strona D-082/D-107). '.
                'Zastane deklaracje: '.trim(preg_replace('/\s+/', ' ', $deklaracje) ?? ''),
            );

            $this->assertNotSame(
                '--spacing-0',
                $trafienie[1],
                "Reguła `{$gdzie['selektor']}` ustawia odstęp na zero. To jest dokładnie ten stan, ".
                "przez który zgłoszono usterkę: {$para}.",
            );
        }
    }

    public function test_tokeny_odstepow_rosna_razem_z_pismem(): void
    {
        // Cała reguła wyżej opiera się na tym, że `--spacing-*` jest w `rem`.
        // Gdyby ktoś przepisał je na piksele, asercje dalej by przechodziły,
        // a odstęp przy czcionce przeglądarki 200 % zostałby ten sam —
        // czyli o połowę za mały względem pisma.
        $tokeny = $this->css('tokens.css');

        foreach (['--spacing-3', '--spacing-4', '--spacing-5', '--spacing-6'] as $token) {
            // `preg_match` w `assertSame`, a nie `assertMatchesRegularExpression`:
            // ta druga przy oblaniu wypisuje CAŁY arkusz tokenów do komunikatu,
            // i prawdziwy powód ginie w kilkuset linijkach.
            $this->assertSame(
                1,
                preg_match('/'.preg_quote($token, '/').':\s*calc\(\s*[\d.]+rem\s*\*\s*var\(--user-layout-scale,\s*1\)\s*\)\s*;/', $tokeny),
                "Token {$token} musi zostać w `rem`. W pikselach odstęp między akapitami ".
                'przestaje rosnąć razem z powiększoną czcionką przeglądarki.',
            );
        }
    }

    public function test_naglowek_przepisu_nie_nadpisuje_arkusza_klasami_marginesu(): void
    {
        $autor = $this->user('autor');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Rosół babci Zofii',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'source_person' => 'babci Zofii',
            'source_note' => "Babcia gotowała go w sobotę wieczorem.\nW niedzielę był już gotowy.",
        ]);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'ingredient_text' => 'pół kurczaka',
        ]);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Zalej mięso zimną wodą.',
        ]);

        $html = $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        // NAGŁÓWEK PRZEPISU, A NIE BELKA SERWISU. Pierwsza wersja tego testu
        // szukała po prostu `<header>…</header>` i trafiała w górny pasek
        // strony — przez co przechodziła także wtedy, gdy `mb-2`, `mt-0`
        // i `mb-4` wróciły na swoje miejsce (sprawdzone: kontrola ujemna
        // świeciła na zielono). Zaczynamy od `<article class="… przepis-uklad">`.
        $this->assertSame(
            1,
            preg_match('/<article[^>]*\bprzepis-uklad\b[^>]*>\s*<header\b[^>]*>(.*?)<\/header>/s', $html, $trafienie),
            'Nie znalazłem nagłówka wewnątrz `<article class="stack przepis-uklad">`. '.
            'Jeśli zmienił się układ strony przepisu, popraw ten wzorzec — bez niego '.
            'test przestaje cokolwiek sprawdzać.',
        );

        $naglowek = $trafienie[1];

        foreach (['mt-0', 'mb-0', 'mb-1', 'mb-2', 'mb-3', 'mb-4', 'mb-5', 'mb-6'] as $klasa) {
            $this->assertSame(
                0,
                preg_match('/class="[^"]*(?<![\w-])'.preg_quote($klasa, '/').'(?![\w-])[^"]*"/', $naglowek),
                "W nagłówku przepisu wróciła klasa `{$klasa}`. Utility leży w warstwie stojącej ".
                'PO `components`, więc przebija reguły rytmu z app.css — a wtedy odstęp zależy '.
                'od klasy w jednym szablonie zamiast od arkusza, który obsługuje każdy przepis. '.
                'Jeśli ten odstęp naprawdę ma być inny, dopisz regułę do `.przepis-uklad > header`.',
            );
        }
    }
}
