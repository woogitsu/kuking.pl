<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Każde `var(--color-…)` w arkuszach wskazuje zmienną, która istnieje.
 *
 * SKĄD TEN TEST (audyt B1, znalezisko 9)
 * `tagi-w-opisie.css` miało `color: var(--color-text)`, a takiej zmiennej nie
 * ma. Deklaracja z nieistniejącą zmienną bez wartości zapasowej jest NIEWAŻNA
 * i nie daje żadnego błędu — kolor dziedziczył się przypadkiem po `body`.
 * Po przeniesieniu podpowiedzi do ciemnej karty tekst straciłby kolor
 * i nikt by nie wiedział dlaczego.
 *
 * KONTROLA DODATNIA: `test_miara_widzi_brakujaca_zmienna`.
 */
class UzyteZmienneKolorowIstniejaTest extends TestCase
{
    public function test_zadna_regula_nie_uzywa_niezdefiniowanego_koloru(): void
    {
        $css = $this->wszystkieArkusze();

        $brakujace = $this->brakujace($css);

        $this->assertGreaterThan(20, count($this->zdefiniowane($css)), 'Odczytano za mało zmiennych kolorów — test nic by nie sprawdzał.');
        $this->assertSame([], $brakujace, 'Te zmienne kolorów są użyte, ale nigdzie nie zdefiniowane: '.implode(', ', $brakujace));
    }

    public function test_miara_widzi_brakujaca_zmienna(): void
    {
        $css = ':root { --color-ink: #000; } .a { color: var(--color-ink); } .b { color: var(--color-text); }';

        $this->assertSame(['--color-text'], $this->brakujace($css));
    }

    /** @return list<string> */
    private function brakujace(string $css): array
    {
        preg_match_all('/var\(\s*(--color-[\w-]+)/', $css, $uzyte);

        return array_values(array_diff(array_unique($uzyte[1]), $this->zdefiniowane($css)));
    }

    /** @return list<string> */
    private function zdefiniowane(string $css): array
    {
        preg_match_all('/(--color-[\w-]+)\s*:/', $css, $m);

        return array_values(array_unique($m[1]));
    }

    private function wszystkieArkusze(): string
    {
        $css = '';

        foreach (glob(resource_path('css/*.css')) ?: [] as $plik) {
            $css .= (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($plik));
        }

        return $css;
    }
}
