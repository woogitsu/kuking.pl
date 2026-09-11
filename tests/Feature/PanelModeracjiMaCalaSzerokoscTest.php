<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Panel moderacji bierze całą szerokość, a reszta serwisu NIE (#365).
 *
 * ZGŁOSZENIE WŁAŚCICIELA, ze zrzutów: „patrz szerokość strony dla użytkownika
 * a tą dla admina i moderatora, dla admina i moderatora jest wąskie, przez co
 * informacje trzeba przewijać, zrób dla admina i moderatora 100% szerokości".
 * Na zrzucie `/admin/uzytkownicy` miał widoczny poziomy pasek przewijania,
 * a kolumna „Stan konta" była ucięta w połowie słowa.
 *
 * ZMIERZONE (Chromium, dane demo, `/admin/uzytkownicy`):
 *
 *   okno    stan     rama    treść  kontener tabeli  tabela   przewijanie
 *   1920    przed    1040     720        686          1358    TAK
 *   1920    po       1920    1600       1566          1566    nie
 *   1280    przed    1040     720        686          1358    TAK
 *   1280    po       1280     960        926          1358    TAK (mniej o 240 px)
 *    320    przed     320     320        286          1358    TAK
 *    320    po        320     320        286          1358    TAK — i tak ma być
 *
 * Przy 320 px tabela dalej jeździ w bok W SWOIM kontenerze i to jest
 * poprawne: chroni to stronę przed przewijaniem w bok CAŁĄ (WCAG 2.2 AA
 * 1.4.10). Przy 1280 px tabela dalej nie mieści się w całości — potrzebuje
 * 1358 px, a kolumna treści ma 926 — więc poprawka zamyka zgłoszenie na
 * ekranie 1600+, a nie wszędzie.
 *
 * TEST MA DWIE POŁOWY I ŻADNA BEZ DRUGIEJ NIC NIE DOWODZI. Sama asercja
 * „panel jest szeroki" przeszłaby także dla zmiany, która rozepchnęła cały
 * serwis — a wtedy kolumna czytania straciłaby swoje 45rem wszędzie.
 */
class PanelModeracjiMaCalaSzerokoscTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_rama_panelu_bierze_cala_dostepna_szerokosc(): void
    {
        $css = $this->css();
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertMatchesRegularExpression(
            '/\.app-body\[data-tryb-panelu\]\s*\{[^}]*max-width:\s*var\(--container-panel\)\s*;/s',
            $css,
            'Rama panelu nie bierze własnego sufitu — zostaje przy ramie ekranu do czytania '
            .'i moderator dalej przewija tabelę w bok na monitorze 1920 px.',
        );

        $this->assertMatchesRegularExpression(
            '/--container-panel:\s*min\([^;]*100%[^;]*\)\s*;/',
            $tokeny,
            '`--container-panel` nie jest liczony z dostępnego obszaru.',
        );

        // Sufit panelu w PIKSELACH, nie w `rem` (D-082, D-107): to liczba dla
        // ekranu, nie dla pisma — w `rem` podwoiłaby się razem z korzeniem
        // przy czcionce przeglądarki 200%, choć monitor od tego nie urósł.
        $this->assertSame(
            1,
            preg_match('/--sufit-panel:\s*([^;]+);/', $tokeny, $sufit),
            'Brak tokenu `--sufit-panel`.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\d\s*r?em/',
            $sufit[1],
            'Sufit panelu zapisany w `rem` — przy czcionce przeglądarki 200% podwoi się razem '
            .'z korzeniem (D-082, D-107).',
        );
    }

    public function test_kolumna_tresci_panelu_przestaje_byc_kolumna_czytania(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.app-body\[data-tryb-panelu\]\s+\.app-main\s*\{[^}]*max-width:\s*none\s*;/s',
            $this->css(),
            'Rama panelu urosła, ale kolumna treści została przy 45rem — tabela dostaje dalej '
            .'720 px, a pustka przenosi się tylko o kawałek w prawo.',
        );
    }

    /**
     * DRUGA POŁOWA: RESZTA SERWISU ZOSTAJE WĄSKA.
     */
    public function test_ekran_poza_panelem_zostaje_przy_kolumnie_czytania(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/(?<!\])\.app-main\s*\{[^}]*max-width:\s*var\(--container-content\)\s*;/s',
            $css,
            'Zwykły `<main>` stracił sufit kolumny czytania — poprawka panelu rozepchnęła cały serwis.',
        );

        // Sufit panelu ma dotyczyć WYŁĄCZNIE trybu panelu. Gdyby `--container-panel`
        // trafił do reguły bez `[data-tryb-panelu]`, każda strona serwisu
        // dostałaby 1920 px ramy.
        // Komentarze wycinamy, zanim zapytamy o selektory — inaczej „selektorem"
        // staje się akapit uzasadnienia stojący nad regułą.
        $bezKomentarzy = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        preg_match_all(
            '/([^{};]+)\{[^{}]*var\(--container-panel\)[^{}]*\}/',
            $bezKomentarzy,
            $uzycia,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($uzycia, 'Nic nie używa `--container-panel` — sufit panelu jest martwy.');

        foreach ($uzycia as $uzycie) {
            $this->assertStringContainsString(
                '[data-tryb-panelu]',
                $uzycie[1],
                'Sufit panelu trafił do reguły spoza trybu panelu ('.trim($uzycie[1]).') — '
                .'szeroka rama rozlałaby się na cały serwis.',
            );
        }
    }

    /**
     * PRZEWIJANIE TABELI W SWOIM KONTENERZE ZOSTAJE.
     *
     * Przy 320 px tabela kont i tak nie zmieści się w całości (1358 px przy
     * czcionce zwykłej). Ma wtedy jeździć w bok W SWOIM kontenerze, a nie
     * wypychać dokumentu — to jest poprawka z #294 i WCAG 2.2 AA 1.4.10,
     * a ta zmiana nie ma prawa jej zdjąć „przy okazji".
     */
    public function test_tabela_dalej_przewija_sie_w_swoim_kontenerze(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.tabela-kont-przewijanie\s*\{[^}]*overflow-x:\s*auto\s*;/s',
            (string) file_get_contents(resource_path('css/ekran-uzytkownikow.css')),
            'Kontener tabeli stracił własne przewijanie — przy 320 px w bok pojedzie cała strona.',
        );
    }
}
