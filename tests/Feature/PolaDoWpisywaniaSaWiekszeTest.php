<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pola do wpisywania są większe — i liczone w pikselach (#365).
 *
 * ZGŁOSZENIE WŁAŚCICIELA: pola są za małe. Dotąd pole brało minimum
 * PRZYCISKU (`--control-height-min`, 48 px). Przycisk się klika, a w pole się
 * patrzy i pisze.
 *
 * ZMIERZONE (Chromium, `/register` i formularz komentarza, dane demo):
 *
 *   czcionka przeglądarki    pole jednowierszowe    pole wielowierszowe
 *   zwykła (16 px)               56 → 64 px             128 → 176 px
 *   200% (32 px)                108 → 124 px            256 → 235 px
 *
 * Dwie rzeczy widać w tej tabeli i obie są sednem tej zmiany:
 *
 *  1. SAMO PODNIESIENIE MINIMUM NIC BY NIE DAŁO. Pole miało 56 px wysokości
 *     samo z siebie (18 px pisma × 1,6 interlinii + 2 × 12 px wcięcia
 *     + 2 × 2 px obwódki), więc minimum 48 px nie dotykało go nigdy. Rośnie
 *     więc WCIĘCIE, a minimum jest dolną granicą przy małym piśmie.
 *  2. W PIKSELACH, NIE W `rem` (D-082, D-107). Pole wielowierszowe przy
 *     podwojonej czcionce robiło się WYŻSZE, niż potrzebuje pięć wierszy
 *     (`8rem` = 256 px przy 235 px treści), bo liczba dla ekranu była
 *     zapisana w jednostce pisma.
 */
class PolaDoWpisywaniaSaWiekszeTest extends TestCase
{
    private function tokeny(): string
    {
        return (string) file_get_contents(resource_path('css/tokens.css'));
    }

    private function token(string $nazwa): string
    {
        $this->assertSame(
            1,
            preg_match('/(?<![a-z-])'.preg_quote($nazwa, '/').':\s*([^;]+);/', $this->tokeny(), $trafienie),
            "W `tokens.css` nie ma tokenu `{$nazwa}`.",
        );

        return trim($trafienie[1]);
    }

    public function test_wysokosc_pola_jest_w_pikselach_a_nie_w_jednostce_pisma(): void
    {
        foreach (['--pole-wysokosc-min', '--pole-wielowierszowe-min'] as $nazwa) {
            $wartosc = $this->token($nazwa);

            $this->assertMatchesRegularExpression(
                '/^\d+px$/',
                $wartosc,
                'Token `'.$nazwa.'` ma wartość „'.$wartosc.'". Wysokość pola istnieje dla ekranu '
                .'i dla palca, a te nie rosną, gdy ktoś powiększy czcionkę w przeglądarce '
                .'(D-082, D-107).',
            );
        }
    }

    public function test_pole_jest_wyzsze_niz_przycisk(): void
    {
        $pole = (int) $this->token('--pole-wysokosc-min');
        $przycisk = $this->token('--control-height-min');

        // Przycisk zostaje przy 3rem (48 px) i to jest w porządku: jego liczba
        // opisuje CEL DOTYKOWY, a nie miejsce na literę.
        $this->assertSame('3rem', $przycisk, 'Zmieniło się minimum przycisku — to nie jest ta zmiana.');

        $this->assertGreaterThan(
            48,
            $pole,
            'Pole nie jest wyższe niż przycisk — zgłoszenie właściciela („pola za małe") zostaje otwarte.',
        );
    }

    public function test_pole_bierze_te_liczby_i_pismo_zostaje_typograficzne(): void
    {
        $tokeny = $this->tokeny();

        $this->assertMatchesRegularExpression(
            '/\.field-input\s*\{[^}]*min-height:\s*var\(--pole-wysokosc-min\)\s*;/s',
            $tokeny,
            'Pole nie bierze własnego minimum wysokości.',
        );

        $this->assertMatchesRegularExpression(
            '/\.field-input\s*\{[^}]*padding:\s*var\(--spacing-4\)\s*;/s',
            $tokeny,
            'Pole wróciło do ciaśniejszego wcięcia — a to wcięcie, nie minimum, decyduje o jego '
            .'wysokości przy piśmie 18 px.',
        );

        $this->assertMatchesRegularExpression(
            '/textarea\.field-input\s*\{[^}]*min-height:\s*var\(--pole-wielowierszowe-min\)\s*;/s',
            $tokeny,
            'Pole wielowierszowe nie bierze własnego minimum wysokości.',
        );

        // PISMO ZOSTAJE TYPOGRAFICZNE. To jest druga połowa reguły z D-107:
        // w pikselach idzie wysokość, ale nie litera — `--text-body` ma rosnąć
        // razem ze skalą tekstu z ustawień konta (docs/UX_50_PLUS.md: 18 px
        // minimum w polu).
        $this->assertMatchesRegularExpression(
            '/\.field-input\s*\{[^}]*font-size:\s*var\(--text-body\)\s*;/s',
            $tokeny,
            'Pismo w polu przestało być typograficzne — nie urośnie razem ze skalą tekstu.',
        );
    }
}
