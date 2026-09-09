<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Zgłoszenie właściciela, Full HD (1920×1080), konto zalogowane, 9 września:
 * awatar („M” w kółku) zawijał się do DRUGIEGO WIERSZA pod przyciskiem
 * „Dodaj”, zamiast stać w jednym rzędzie z „Powiadomienia” i „Dodaj”. Pasek
 * robił się dzięki temu dwa razy wyższy.
 *
 * PRZYCZYNA (zmierzona w Chromium, nie zgadnięta)
 *
 * Od 80rem `.topbar-inner` jest siatką o trzech kolumnach — trzecia
 * (`.topbar-actions`: „Powiadomienia” z odznaką, „Dodaj”, awatar) miała
 * SZTYWNĄ szerokość `var(--container-rail)` (22rem = 352px), a
 * `.topbar-actions` zachowywało bazowe `flex-wrap: wrap` (potrzebne na
 * wąskim ekranie, żeby strona nie przewijała się w bok — issue #80).
 *
 * `--container-rail` jest w `rem`, więc NIE rośnie z `--user-text-scale`
 * (ustawienie dostępności „większy tekst”, `docs/UX_50_PLUS.md`,
 * `tokens.css`). Rośnie za to `--text-body`, z którego liczy się czcionka
 * przycisków. Przy koncie z `text_scale` 125% suma szerokości „Powiadomienia”
 * + „Dodaj” + awatar przekracza 352 px stałej kolumny — kolumna nie miała
 * jak urosnąć, więc `flex-wrap: wrap` zrobiło jedyną dostępną rzecz: zrzuciło
 * awatar do drugiego wiersza. Zmierzone w Chromium 1920×1080 (Playwright,
 * konto `text_scale = 125`): PRZED poprawką awatar stał 64 px niżej niż
 * środek „Dodaj”; PO — w tym samym wierszu, 0 px różnicy.
 *
 * Właściciel widział to na zwykłym Full HD bez żadnego zoomu — sam
 * `text_scale` konta wystarcza, ekran nie musi być inny.
 *
 * DLACZEGO TEN TEST CZYTA ARKUSZ STYLÓW, A NIE RENDERUJE STRONY
 * Zawijanie jest efektem CSS (`flex-wrap` + szerokość siatki), którego
 * PHPUnit bez prawdziwej przeglądarki nie zmierzy — `assertSee()` nie widzi
 * układu. Test pilnuje więc DWÓCH WŁAŚCIWOŚCI w arkuszu, które razem
 * gwarantują jeden wiersz od 80rem: `.topbar-actions` w kontekście siatki
 * (`.topbar-inner .topbar-actions`) ma `flex-wrap: nowrap`, a trzecia
 * kolumna siatki umie urosnąć ponad `--container-rail` (`max-content` w
 * `minmax()`), zamiast być w nim zamknięta na sztywno.
 *
 * Test przechodzi PO poprawce i celowo NIE PRZECHODZIŁ przed nią (fałszywa
 * zieleń sprawdzona ręcznie: cofnięcie `resources/css/app.css` do wersji
 * sprzed tej zmiany obala oba `assert*` niżej).
 */
class PasekGornyNieZawijaAwataraNaPelnymHdTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * Wycina treść reguły `.topbar-inner .topbar-actions { ... }` — TEJ
     * konkretnej, zagnieżdżonej w kontekście siatki od 80rem, nie bazowej
     * `.topbar-actions { flex-wrap: wrap; ... }`, która musi zostać bez
     * zmian (ona chroni wąski ekran przed przewijaniem w bok).
     */
    private function regulaAkcjiWSiatce(): string
    {
        $trafil = preg_match(
            '/\.topbar-inner\s+\.topbar-actions\s*\{((?:[^{}]|\{[^{}]*\})*)\}/s',
            $this->css(),
            $dopasowanie,
        );

        $this->assertSame(
            1,
            $trafil,
            'W `resources/css/app.css` nie ma reguły `.topbar-inner .topbar-actions '.
            '{ ... }` — albo arkusz zmienił kształt, albo poprawka na zawijanie '.
            'awatara zniknęła.',
        );

        return $dopasowanie[1];
    }

    public function test_akcje_w_siatce_od_80rem_nie_zawijaja_sie(): void
    {
        $regula = $this->regulaAkcjiWSiatce();

        $this->assertMatchesRegularExpression(
            '/flex-wrap:\s*nowrap\s*;/',
            $regula,
            'Od 80rem `.topbar-inner .topbar-actions` musi mieć `flex-wrap: nowrap`. '.
            'Bez tego reguła bazowa `flex-wrap: wrap` zrzuca awatar pod „Dodaj”, '.
            'gdy trzy elementy paska ([]Powiadomienia z odznaką, Dodaj, awatar) nie '.
            'zmieszczą się w jednym wierszu — dokładnie zgłoszenie właściciela '.
            'z Full HD.',
        );
    }

    public function test_trzecia_kolumna_belki_moze_urosnac_ponad_container_rail(): void
    {
        // `.topbar-inner` ma DWIE reguły w arkuszu: bazową (`flex`, dla
        // wąskiego ekranu) i tę od 80rem (`display: grid`, trzy kolumny —
        // patrz komentarz „BELKA DOSTAJE TĘ SAMĄ SIATKĘ, CO TREŚĆ”). Interesuje
        // nas WYŁĄCZNIE ta druga, więc łapiemy wszystkie i wybieramy tę
        // z `display: grid` — inaczej test milcząco sprawdzałby złą regułę.
        $trafienia = [];
        preg_match_all(
            '/\.topbar-inner\s*\{((?:[^{}]|\{[^{}]*\})*)\}/s',
            $this->css(),
            $trafienia,
        );

        $tresc = null;

        foreach ($trafienia[1] as $kandydat) {
            if (str_contains($kandydat, 'display: grid')) {
                $tresc = $kandydat;

                break;
            }
        }

        $this->assertNotNull(
            $tresc,
            'W `resources/css/app.css` nie ma reguły `.topbar-inner { display: grid; ... }` '.
            '(siatka trzykolumnowa od 80rem) — albo arkusz zmienił kształt, albo ta '.
            'reguła zniknęła.',
        );

        $this->assertMatchesRegularExpression(
            '/minmax\(\s*var\(--container-rail\)\s*,\s*max-content\s*\)/',
            $tresc,
            'Trzecia kolumna siatki (akcje po prawej) musi być '.
            '`minmax(var(--container-rail), max-content)`, NIE samo '.
            '`var(--container-rail)`. Sztywna kolumna nie ma jak urosnąć, gdy '.
            'tekst przycisków jest szerszy niż 352 px (np. konto z większą skalą '.
            'tekstu, `--user-text-scale` w `tokens.css`) — a `flex-wrap: nowrap` '.
            'z drugiego testu w tym pliku bez tego tylko UKRYWA problem, obcinając '.
            'akcje zamiast dać im miejsce.',
        );
    }
}
