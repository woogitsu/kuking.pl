<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel moderacji na szerokim telefonie (Galaxy Fold rozłożony) — issue #294.
 *
 * ZGŁOSZENIE WŁAŚCICIELA, ZE ZRZUTÓW
 * Sześć rzeczy naraz na `/admin/uzytkownicy`. Dwie z nich mają tu test
 * regresyjny — reszta (nawigacja jako surowa lista, samotny „|", ucięte
 * zakładki i tabela) była już naprawiona w tej gałęzi, zanim ten plik powstał,
 * i ma własne pilnowanie: `TrybPaneluWMenuTest` (menu), a przepełnienie
 * w poziomie łapie zmierzony pomiar układu w `scripts/dostepnosc.mjs`.
 *
 * CZEMU CSS, NIE PLAYWRIGHT
 * W tym repozytorium nie ma przeglądarki w PHPUnicie — realny układ mierzy
 * wyłącznie `scripts/dostepnosc.mjs` (Node + Playwright), a PHPUnit dla
 * usterek czysto CSS-owych czyta regułę WPROST Z ARKUSZA, tak samo jak
 * `UkladGosciaTest::klasyJednokolumnowe()` i
 * `NaglowekProfiluOdmieniaLicznikiTest`. Sam fakt, że reguła istnieje
 * w pliku, niczego by nie dowodził — dlatego każdy test niżej sprawdza też,
 * że selektor TRAFIA w prawdziwy znacznik z odpowiedzi HTTP, nie w nazwę,
 * która akurat nigdzie nie występuje.
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
}
