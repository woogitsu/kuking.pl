<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Listy w panelu „Aa · Wygląd” mają granicę widoczną na tle panelu.
 *
 * SKĄD TEN TEST (audyt B1, znalezisko 3)
 * `.szybki-wyglad select` miało `1px solid var(--color-border)`: #DDE0D8 na
 * białym panelu to 1,34:1, a w ciemnym motywie 1,38:1. Tło listy różniło się
 * od tła panelu o ok. 1,1:1. WCAG 1.4.11 wymaga 3:1 dla granicy kontrolki.
 * Panel jest na każdej stronie i służy do powiększenia tekstu — osoba ze
 * słabszym wzrokiem widziała dwie listy jak zwykły tekst.
 *
 * Kontrast liczymy z TOKENÓW w `tokens.css` (ta sama metoda co
 * `scripts/kontrast-marki.mjs`), a nie z listy kolorów przepisanej do testu.
 * Zmiana palety albo podmiana tokenu w regule jest więc od razu mierzona.
 *
 * KONTROLA DODATNIA: `test_miara_kontrastu_odroznia` — gdyby odczyt palety
 * się rozjechał, miara nie może zwracać stałej.
 */
class KontrolkiPaneluWygladuMajaWidocznaObwodkeTest extends TestCase
{
    public function test_obwodka_listy_ma_co_najmniej_3_do_1_na_tle_panelu_w_obu_motywach(): void
    {
        $css = $this->bezKomentarzy(resource_path('css/szybki-wyglad.css'));

        $select = $this->regula($css, '.szybki-wyglad select', 'border');
        $panel = $this->regula($css, '.szybki-wyglad-panel', 'background');

        $this->assertSame(1, preg_match('/border\s*:\s*(\d+)px\s+solid\s+var\(--color-([\w-]+)\)/', $select, $obwodka), 'Obwódka listy musi być tokenem koloru.');
        $this->assertGreaterThanOrEqual(2, (int) $obwodka[1], 'Obwódka listy ma tę samą grubość co `.field-input` (2 px).');
        $this->assertSame(1, preg_match('/background\s*:\s*var\(--color-([\w-]+)\)/', $select, $tloListy));
        $this->assertSame(1, preg_match('/background\s*:\s*var\(--color-([\w-]+)\)/', $panel, $tloPanelu));

        foreach ($this->palety() as $motyw => $paleta) {
            foreach (['panelu' => $tloPanelu[1], 'listy' => $tloListy[1]] as $czego => $tlo) {
                $kontrast = $this->kontrast($paleta[$obwodka[2]], $paleta[$tlo]);

                $this->assertGreaterThanOrEqual(
                    3.0,
                    $kontrast,
                    sprintf('%s: obwódka --color-%s na tle %s (--color-%s) ma %.2f:1, a WCAG 1.4.11 wymaga 3:1.', $motyw, $obwodka[2], $czego, $tlo, $kontrast),
                );
            }
        }
    }

    public function test_miara_kontrastu_odroznia(): void
    {
        $this->assertEqualsWithDelta(21.0, $this->kontrast('#000000', '#FFFFFF'), 0.001);

        $jasna = $this->palety()['jasny'];
        // Dokładnie ta para, która była w panelu przed poprawką.
        $this->assertLessThan(3.0, $this->kontrast($jasna['border'], $jasna['surface-raised']));
    }

    /** @return array{jasny: array<string, string>, ciemny: array<string, string>} */
    private function palety(): array
    {
        $css = $this->bezKomentarzy(resource_path('css/tokens.css'));

        $this->assertSame(1, preg_match('/@theme\s*\{([^}]+)\}/', $css, $jasny));
        $this->assertSame(1, preg_match('/:root\[data-theme="dark"\],\s*\.blok-ciemny\s*\{([^}]+)\}/', $css, $ciemny));

        $czytaj = static function (string $blok): array {
            preg_match_all('/--color-([\w-]+):\s*(#[\da-f]{6})\s*;/i', $blok, $m);

            return array_combine($m[1], $m[2]);
        };

        $paletaJasna = $czytaj($jasny[1]);
        $this->assertGreaterThanOrEqual(25, count($paletaJasna), 'Odczytano za mało kolorów — pomiar byłby pusty.');

        return ['jasny' => $paletaJasna, 'ciemny' => array_merge($paletaJasna, $czytaj($ciemny[1]))];
    }

    private function kontrast(string $a, string $b): float
    {
        $luminancja = static function (string $hex): float {
            $kanaly = array_map(static function (string $c): float {
                $v = hexdec($c) / 255;

                return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $kanaly[0] + 0.7152 * $kanaly[1] + 0.0722 * $kanaly[2];
        };

        $x = $luminancja($a);
        $y = $luminancja($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private function bezKomentarzy(string $plik): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($plik));
    }

    /**
     * OSTATNIA reguła z podanym selektorem (całym), która ustawia daną
     * właściwość — ostatnia, bo to ona wygrywa w kaskadzie. Selektor
     * `.szybki-wyglad select` stoi w arkuszu w trzech regułach, a tylko
     * jedna z nich rysuje obwódkę.
     */
    private function regula(string $css, string $selektor, string $wlasciwosc): string
    {
        $wzorzec = '/(?:^|[},])\s*([^{}]*'.preg_quote($selektor, '/').'(?![\w-])[^{}]*)\{([^{}]*)\}/m';
        preg_match_all($wzorzec, $css, $m);

        $zWlasciwoscia = array_values(array_filter(
            $m[2],
            static fn (string $cialo): bool => preg_match('/(?:^|;)\s*'.preg_quote($wlasciwosc, '/').'\s*:/', $cialo) === 1,
        ));

        $this->assertNotSame([], $zWlasciwoscia, "Nie znalazłem reguły {$selektor} z właściwością {$wlasciwosc}.");

        return $zWlasciwoscia[array_key_last($zWlasciwoscia)];
    }
}
