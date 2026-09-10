<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel moderacji na szerokim telefonie (Galaxy Fold rozłożony) — issue #294.
 *
 * ZGŁOSZENIE WŁAŚCICIELA, ZE ZRZUTÓW
 * Sześć rzeczy naraz na `/admin/uzytkownicy`. Dwie z nich mają test
 * regresyjny niżej — reszta (nawigacja jako surowa lista, samotny „|", ucięte
 * zakładki jako WIDOK) była już naprawiona w tej gałęzi, zanim ten plik
 * powstał, i ma własne pilnowanie: `TrybPaneluWMenuTest` (menu).
 *
 * DWA DALSZE ZNALEZISKA, JUŻ PO DOŁOŻENIU PANELU DO `scripts/dostepnosc.mjs`
 * Sam pomiar wykrył dwa naruszenia WCAG 2.2 AA 1.4.10 (Reflow), których
 * ŻADEN zrzut ekranu nie pokazywał — widać je dopiero przy PRAWDZIWYM
 * powiększeniu czcionki przeglądarki (`Page.setFontSizes`, wariant
 * „czcionka przeglądarki 200%", odpowiednik „Rozmiar czcionki: bardzo duży"
 * w Chrome — NIE naszej skali tekstu `data-text-scale`) na 320/360 px:
 * pojedyncza zakładka „W trakcie usuwania (0)" i natywne pole
 * `<input type="date">` same, osobno, były szersze niż okno. Obie mają tu
 * test regresyjny.
 *
 * CZEMU CSS, NIE PLAYWRIGHT
 * W tym repozytorium nie ma przeglądarki w PHPUnicie — realny układ mierzy
 * wyłącznie `scripts/dostepnosc.mjs` (Node + Playwright), a PHPUnit dla
 * usterek czysto CSS-owych czyta regułę WPROST Z ARKUSZA, tak samo jak
 * `UkladGosciaTest::klasyJednokolumnowe()`, `NaglowekProfiluOdmieniaLicznikiTest`
 * i `PasekGornyNaTelefonieTest`. Sam fakt, że reguła istnieje w pliku,
 * niczego by nie dowodził — dlatego każdy test niżej sprawdza też, że
 * selektor TRAFIA w prawdziwy znacznik z odpowiedzi HTTP, nie w nazwę, która
 * akurat nigdzie nie występuje.
 */
class PanelSzerokiTelefonTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * Punkt 2 zgłoszenia: „Treść jest wciśnięta w lewe ~55% ekranu, po prawej
     * zostaje duży pusty obszar."
     *
     * PRZYCZYNA (zmierzona 10 września, opisana w app.css przy tej regule):
     * od 80rem `.app-body` rezerwuje TRZECIĄ kolumnę (`.app-rail`, sztywne
     * 22rem) na każdym ekranie — nawet gdy żaden slot `rail` nic tam nie
     * wkłada. Panel moderacji nigdy takiego slotu nie podaje, więc ta kolumna
     * jest tam zawsze pusta.
     *
     * Ten test pilnuje DWÓCH rzeczy naraz, bo jedna bez drugiej nic nie
     * dowodzi:
     *   1. `.app-body[data-tryb-panelu]` od 80rem wraca do DWÓCH kolumn
     *      (nawigacja + treść), bez `var(--container-rail)`;
     *   2. `.app-body` BEZ tego atrybutu w TYM SAMYM bloku nadal ma TRZY
     *      kolumny — czyli poprawka nie zdjęła rezerwacji szynie wszystkim
     *      ekranom serwisu, tylko panelowi.
     */
    public function test_panel_nie_rezerwuje_pustej_kolumny_szyny_od_80rem(): void
    {
        $css = $this->css();

        if (! preg_match('/@media\s*\(min-width:\s*80rem\)\s*\{(.*)\n  \}\n/s', $css, $blokDopasowanie)) {
            $this->fail('Nie znaleziono bloku `@media (min-width: 80rem)` w app.css — arkusz zmienił kształt.');
        }
        $blok80rem = $blokDopasowanie[1];

        $this->assertGreaterThan(
            200,
            strlen($blok80rem),
            'Blok `@media (min-width: 80rem)` jest podejrzanie krótki — zły dopasowany fragment?',
        );

        // (1) Panel: dwie kolumny, bez kolumny szyny.
        $this->assertMatchesRegularExpression(
            '/\.app-body\[data-tryb-panelu\]\s*\{\s*grid-template-columns:\s*'
            .'var\(--container-sidenav\)\s+minmax\(0,\s*1fr\)\s*;/',
            $blok80rem,
            'Brak (albo zły kształt) reguły `.app-body[data-tryb-panelu]` w bloku 80rem — panel dalej '
            .'rezerwowałby pustą kolumnę szyny na szerokim telefonie (issue #294, punkt 2).',
        );

        $this->assertStringNotContainsString(
            'container-rail',
            $this->wytnijRegule($blok80rem, '.app-body[data-tryb-panelu]'),
            'Reguła panelu nadal wspomina `--container-rail` — kolumna szyny nie została zdjęta.',
        );

        // (2) Kontrola dodatnia: zwykły `.app-body` (bez atrybutu panelu)
        // w TYM SAMYM bloku ma nadal TRZY kolumny. Bez tej połowy testu
        // sabotaż „usuń szynę WSZĘDZIE" przeszedłby równie dobrze.
        $this->assertMatchesRegularExpression(
            '/(?<!\[data-tryb-panelu\]\s)\.app-body\s*\{\s*grid-template-columns:\s*'
            .'var\(--container-sidenav\)\s+minmax\(0,\s*1fr\)\s+var\(--container-rail\)\s*;/',
            $blok80rem,
            'Zwykły `.app-body` stracił trzecią kolumnę (szynę) — poprawka panelu zabrała ją '
            .'całemu serwisowi, a miała zabrać tylko panelowi.',
        );

        // I na żywo: strona panelu naprawdę niesie atrybut, który ta reguła czyta.
        $moderator = $this->moderator();
        $html = (string) $this->actingAs($moderator)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<div class="app-body[^"]*"\s+data-tryb-panelu\s*>/',
            $html,
            '`/admin/uzytkownicy` nie niesie `data-tryb-panelu` na `.app-body` — selektor '
            .'z app.css nigdy by tu nie trafił.',
        );

        // A zwykły ekran serwisu (poza panelem) tego atrybutu NIE dostaje —
        // inaczej ten sam moderator straciłby szynę na każdej innej stronie.
        $htmlPozaPanelem = (string) $this->actingAs($moderator)->get('/home')->assertOk()->getContent();
        $this->assertStringNotContainsString(
            'data-tryb-panelu',
            $htmlPozaPanelem,
            'Ekran poza panelem niesie `data-tryb-panelu` — straciłby przez to trzecią kolumnę (szynę) '
            .'wszędzie, nie tylko w panelu.',
        );
    }

    /**
     * Wycina treść JEDNEJ reguły CSS zaczynającej się od danego selektora
     * (do pierwszego domykającego `}`). Ten sam rodzaj cięcia co `wycinek()`
     * w testach onboardingu — nie łapiemy sąsiedniej reguły przez pomyłkę.
     */
    private function wytnijRegule(string $css, string $selektor): string
    {
        $start = strpos($css, $selektor);
        $this->assertNotFalse($start, "Selektor „{$selektor}” nie występuje w podanym fragmencie CSS.");

        $koniec = strpos($css, '}', $start);
        $this->assertNotFalse($koniec, "Reguła „{$selektor}” nie jest domknięta klamrą.");

        return substr($css, $start, $koniec - $start);
    }

    /**
     * Punkt 4 zgłoszenia: „Wróć do Kuking jest dwa razy".
     *
     * Oba odnośniki są ZAMIERZONE poniżej 64rem — `TrybPaneluWMenuTest`
     * pilnuje wprost, że na wąskim telefonie mają wystąpić OBA (menu boczne
     * i pasek dolny to tam „dwa różne miejsca", rzadko widoczne naraz). Na
     * szerokim telefonie oba mieszczą się w jednym kadrze bez przewijania —
     * stąd zgłoszenie. Poprawka nie rusza HTML-a (żaden z dotychczasowych
     * testów treści się nie psuje) — chowa górny odnośnik WYŁĄCZNIE w paśmie
     * 48rem–63.999rem, czyli dokładnie w przedziale z opisu zgłoszenia
     * („Fold rozłożony ląduje między 768 a 1280").
     */
    public function test_gorne_wyjscie_z_panelu_znika_tylko_na_szerokim_telefonie(): void
    {
        $css = $this->css();

        if (! preg_match(
            '/@media\s*\(min-width:\s*48rem\)\s+and\s*\(max-width:\s*63\.999rem\)\s*\{([^}]*\}[^}]*)\}/s',
            $css,
            $dopasowanie,
        )) {
            $this->fail(
                'Brak bloku `@media (min-width: 48rem) and (max-width: 63.999rem)` w app.css — '
                .'reguła chowająca duplikat „Wróć do Kuking” zniknęła albo zmieniła próg.',
            );
        }

        $this->assertMatchesRegularExpression(
            '/\.side-nav\[data-tryb-panelu\]\s+\.side-nav-powrot\s*\{\s*display:\s*none\s*;/',
            $dopasowanie[1],
            'Blok 48rem–63.999rem istnieje, ale nie chowa `.side-nav-powrot` — górny odnośnik '
            .'nie zniknie na szerokim telefonie.',
        );

        // Kontrola dodatnia: pasek dolny (`.bottom-nav-panel`) NIE ma w tym
        // samym bloku żadnej reguły `display: none` — inaczej zniknęłyby
        // OBA wyjścia naraz, zamiast zostać dokładnie jednym.
        $this->assertDoesNotMatchRegularExpression(
            '/\.bottom-nav-panel\s*\{[^}]*display:\s*none/',
            $dopasowanie[1],
            'Pasek dolny panelu jest schowany w tym samym paśmie szerokości — moderator '
            .'straciłby JEDYNE wyjście z panelu na szerokim telefonie.',
        );

        // Na żywo: selektor faktycznie trafia w prawdziwy znacznik.
        $moderator = $this->moderator();
        $html = (string) $this->actingAs($moderator)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<a class="side-nav-item side-nav-powrot" href="[^"]*">/',
            $html,
            '`.side-nav-powrot` nie istnieje w HTML-u panelu — selektor CSS nic by nie chował.',
        );
        $this->assertStringContainsString(
            'class="bottom-nav bottom-nav-panel"',
            $html,
            'Brak paska dolnego panelu w HTML-u — bez niego chowanie górnego odnośnika zostawiłoby '
            .'moderatora bez żadnego wyjścia na szerokim telefonie.',
        );
    }

    /**
     * Komentarze precz, zanim cokolwiek sprawdzimy — ten sam powód co
     * w `PasekGornyNaTelefonieTest`: komentarze w tym repozytorium cytują
     * to, czego w kodzie być NIE MOŻE („NIE `white-space: nowrap`"), więc
     * asercja na surowym pliku trafiałaby we własne uzasadnienie.
     */
    private function cssBezKomentarzy(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
    }

    /** Fragment arkusza od PIERWSZEGO wystąpienia selektora do końca jego bloku. */
    private function regula(string $css, string $selektor, int $od = 0): string
    {
        $start = strpos($css, $selektor, $od);
        $this->assertNotFalse($start, "W arkuszu nie ma (już) reguły „{$selektor}”.");

        $koniec = strpos($css, '}', $start);
        $this->assertNotFalse($koniec, "Reguła „{$selektor}” nie jest domknięta klamrą.");

        return substr($css, $start, $koniec - $start + 1);
    }

    /**
     * Naruszenie znalezione DOPIERO po dołożeniu panelu do
     * `scripts/dostepnosc.mjs`: „Rząd zakładek jest ucięty" (issue #294) było
     * naprawione dla RZĘDU (`.tabs { flex-wrap: wrap }`), ale JEDNA zakładka
     * — „W trakcie usuwania (0)" — sama, bez sąsiadów, była szersza niż
     * ekran przy prawdziwym powiększeniu czcionki przeglądarki.
     *
     * ZMIERZONE (`scripts/dostepnosc.mjs`, `czcionka przeglądarki 200%`, patrz
     * `Page.setFontSizes` w skrypcie): `/admin/uzytkownicy`, 320 i 360 px —
     * `documentElement.scrollWidth` 408 px, winny `a.tab` kończył się na
     * x=501. Po zamianie `white-space: nowrap` na `overflow-wrap: anywhere`
     * na `.tab` (ten sam mechanizm co `.side-nav-item`): 0 przepełnień na obu
     * szerokościach, we wszystkich trzech skalach czcionki.
     */
    public function test_zakladka_panelu_zawija_etykiete_zamiast_wypychac_strone(): void
    {
        $css = $this->cssBezKomentarzy();
        $regulaTab = $this->regula($css, '.tab {');

        $this->assertStringNotContainsString(
            'white-space: nowrap',
            $regulaTab,
            '`.tab` znowu ma `white-space: nowrap` — pojedyncza długa zakładka '
            .'(„W trakcie usuwania (0)”) nie zejdzie poniżej swojej pełnej '
            .'szerokości i przy dużej czcionce przeglądarki wypchnie stronę '
            .'w bok (WCAG 2.2 AA 1.4.10 Reflow).',
        );
        $this->assertStringContainsString(
            'overflow-wrap: anywhere',
            $regulaTab,
            '`.tab` nie pozwala etykiecie zejść do drugiego wiersza — bez tego '
            .'usunięcie samego `nowrap` nie naprawia niczego (etykieta i tak '
            .'nie ma jak się złamać).',
        );

        // Kontrola dodatnia: `.tabs` (rząd) dalej zawija CAŁE zakładki do
        // nowego wiersza — to jest OSOBNA poprawka (już w tej gałęzi) i ten
        // test nie może przypadkiem potwierdzać jej zamiast własnej.
        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->regula($css, '.tabs {'),
            '`.tabs` straciło zawijanie rzędu — to inna usterka z tego samego '
            .'zgłoszenia (zakładki ucięte w poziomie) i nie powinna wracać '
            .'przy okazji tej poprawki.',
        );

        // Na żywo: selektor `.tab` trafia w prawdziwy znacznik na liście kont.
        $moderator = $this->moderator();
        $html = (string) $this->actingAs($moderator)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'class="tab"',
            $html,
            '`/admin/uzytkownicy` nie ma ani jednej zakładki `.tab` — selektor '
            .'z app.css nigdy by tu nie trafił.',
        );
        $this->assertStringContainsString(
            'W trakcie usuwania',
            $html,
            'Zniknęła zakładka „W trakcie usuwania” — to jej etykieta była '
            .'zmierzonym najgorszym przypadkiem tej usterki.',
        );
    }

    /**
     * Drugie naruszenie znalezione dopiero po dołożeniu panelu do skryptu:
     * `<input type="date">` — jedyny taki kontrolek w całym serwisie
     * (`grep type="date" resources/views` poza tym plikiem nic nie znajduje)
     * — ma WŁASNY, niezależny od `width`/`min-width` próg szerokości, którego
     * przeglądarka nie zejdzie poniżej.
     *
     * ZMIERZONE: `/admin/uzytkownicy`, 320 px, czcionka przeglądarki 200% —
     * `.filtr-data` kończył się na x=408 (samo pole, bez żadnego sąsiada),
     * a CAŁA STRONA przewijała się w bok. Naprawą jest WŁASNY, kontenerowy
     * scroll na `.filtr-data` — ten sam wzorzec co `.tabela-kont-przewijanie`
     * kawałek niżej w tym samym pliku — a nie próba zmusić natywny kontrolek
     * do zejścia poniżej jego własnego minimum.
     */
    public function test_pole_daty_w_panelu_przewija_sie_we_wlasnym_kontenerze_nie_wypycha_strony(): void
    {
        $sciezka = resource_path('css/ekran-uzytkownikow.css');
        $this->assertFileExists($sciezka);

        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($sciezka));

        // Selektor `.filtry-kont .filtr-data` występuje w pliku DWA razy
        // (flex-basis, potem ta poprawka) — bierzemy DRUGIE wystąpienie, nie
        // pierwsze z brzegu.
        $pierwszy = strpos($css, '.filtry-kont .filtr-data {');
        $this->assertNotFalse($pierwszy, 'Brak reguły `.filtry-kont .filtr-data` w arkuszu.');

        $regulaScrolla = $this->regula($css, '.filtry-kont .filtr-data {', $pierwszy + 1);

        $this->assertStringContainsString(
            'overflow-x: auto',
            $regulaScrolla,
            'Pole daty w panelu nie ma własnego przewijania — natywny '
            .'`<input type="date">` nie zejdzie poniżej swojej minimalnej '
            .'szerokości i przy dużej czcionce przeglądarki wypchnie CAŁĄ '
            .'stronę w bok (WCAG 2.2 AA 1.4.10 Reflow).',
        );

        // Na żywo: selektor trafia w prawdziwy znacznik.
        $moderator = $this->moderator();
        $html = (string) $this->actingAs($moderator)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<div class="filtr-data">\s*<div class="field/',
            $html,
            '`.filtr-data` nie istnieje w HTML-u panelu (albo zmienił kształt) — '
            .'selektor CSS nigdy by tu nie trafił.',
        );
        $this->assertStringContainsString('type="date"', $html);
    }
}
