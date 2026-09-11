<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Rama strony to `min(sufit, dostępny obszar)`, a nie sama liczba (#365).
 *
 * DECYZJA WŁAŚCICIELA, zapisana w issue: „zamiast stałe piksele, nie lepiej
 * dać % dostępnego obszaru?". Każda rama liczy się od dziś tak samo:
 *
 *     max-width: min(sufit, 100%)      + boczne wcięcie `--container-margines`
 *
 * czyli szerokość treści to `min(sufit − margines, 100% − margines)`.
 *
 * UCZCIWIE O SKUTKU — I DLATEGO TEN TEST CZYTA ARKUSZ, A NIE MIERZY PIKSELE.
 * Przy `box-sizing: border-box` i `width: 100%`, które te warstwy mają od
 * #294, jest to zapis tego, co przeglądarka robiła i przedtem: zmierzone
 * 11 września na `/`, `/home` i `/przepisy/…` przy 1280 i 1920 px — 0 px
 * różnicy. Test pilnuje więc ZAPISU, bo to zapis był przedmiotem decyzji:
 * ograniczenie ma stać w jednym miejscu i w wartości, a nie wynikać z tego,
 * że ktoś pamiętał dopisać `width: 100%` obok.
 *
 * Czego ten test NIE pilnuje: wysokości sufitu. Sufit zostaje przy 1424 px,
 * bo nadwyżka nie ma dziś gdzie pójść (uzasadnienie przy tokenach w
 * `tokens.css`).
 */
class RamaLiczySieZDostepnegoObszaruTest extends TestCase
{
    private function tokeny(): string
    {
        return (string) file_get_contents(resource_path('css/tokens.css'));
    }

    /**
     * Wartość tokenu — od dwukropka do średnika, razem z nawiasami.
     */
    private function token(string $nazwa): string
    {
        $tokeny = $this->tokeny();

        $this->assertSame(
            1,
            preg_match('/(?<![a-z-])'.preg_quote($nazwa, '/').':\s*([^;]+);/s', $tokeny, $trafienie),
            "W `tokens.css` nie ma tokenu `{$nazwa}`.",
        );

        return trim($trafienie[1]);
    }

    public function test_kazda_rama_ma_sufit_i_dostepny_obszar(): void
    {
        foreach ([
            '--container-strona',
            '--container-strona-z-szyna',
            '--container-strona-solo',
            '--container-strona-solo-z-szyna',
            '--container-panel',
        ] as $nazwa) {
            $wartosc = $this->token($nazwa);

            $this->assertStringContainsString(
                'min(',
                $wartosc,
                "Rama `{$nazwa}` nie jest ograniczona dostępnym obszarem — została sztywną liczbą.",
            );

            $this->assertStringContainsString(
                '100%',
                $wartosc,
                "Rama `{$nazwa}` nie odwołuje się do dostępnego obszaru (`100%`).",
            );
        }
    }

    /**
     * SUFIT DALEJ JEST LICZONY ZE SKŁADNIKÓW, NIE WPISANY.
     *
     * Gdyby ktoś przy tej okazji wpisał do sufitu gotowe `1424px`, pierwsza
     * zmiana szerokości szyny albo kolumny czytania rozjechałaby ramę
     * z belką i stopką — czyli dokładnie ta usterka, którą zamykał blok
     * „JEDNA LICZBA DLA TRZECH WARSTW".
     */
    public function test_sufit_dalej_liczy_sie_ze_skladnikow(): void
    {
        foreach ([
            '--sufit-strona' => '--container-content',
            '--sufit-strona-z-szyna' => '--container-rail',
            '--sufit-strona-solo' => '--container-content',
            '--sufit-strona-solo-z-szyna' => '--container-rail',
        ] as $nazwa => $skladnik) {
            $wartosc = $this->token($nazwa);

            $this->assertStringStartsWith(
                'calc(',
                $wartosc,
                "Sufit `{$nazwa}` przestał być liczony.",
            );

            $this->assertStringContainsString(
                $skladnik,
                $wartosc,
                "Sufit `{$nazwa}` nie bierze już `{$skladnik}` — rama rozjedzie się z tym, co w niej stoi.",
            );
        }
    }

    /**
     * MARGINES BOCZNY MA JEDNĄ NAZWĘ W TRZECH WARSTWACH.
     *
     * Rama, belka i stopka muszą zaczynać się w tym samym `x`. Dotąd liczba
     * 24 px stała w nich jako `--spacing-6` w czterech miejscach i nic nie
     * mówiło, że to JEDNA liczba — więc zmiana jednej z nich nie wyglądała
     * na rozjazd, tylko na poprawkę.
     */
    public function test_margines_ramy_jest_jedna_nazwana_liczba(): void
    {
        $this->assertNotSame('', $this->token('--container-margines'));

        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.app-body\s*\{[^}]*padding:\s*0\s+var\(--container-margines\)\s*;/s',
            $css,
            'Rama treści bierze wcięcie boczne skądinąd niż belka i stopka.',
        );

        $this->assertMatchesRegularExpression(
            '/\.topbar-inner,\s*\n\s*\.site-footer-inner\s*\{[^}]*padding-left:\s*var\(--container-margines\)\s*;/s',
            $css,
            'Belka i stopka biorą wcięcie boczne skądinąd niż rama treści.',
        );
    }
}
