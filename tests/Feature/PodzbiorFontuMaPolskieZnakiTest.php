<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Każdy polski znak trafia do deklaracji Inter, a nie do fontu zastępczego (#1000).
 *
 * `LogotypIMarkaTest` sprawdza tylko, że oba pliki WOFF2 LEŻĄ w repozytorium.
 * Nie widzi zakresów `unicode-range`: przycięcie `U+0100-02BA` do `U+0100-017F`
 * minus jedna litera nic nie psuje w testach, a „żurek" znów ma trzy kroje.
 * Ten strażnik liczy pokrycie znak po znaku — to kontrakt, którego musi
 * dotrzymać każdy przyszły, mniejszy podzbiór fontu.
 */
class PodzbiorFontuMaPolskieZnakiTest extends TestCase
{
    /** Pełny alfabet, cyfry i interpunkcja polskiego tekstu. */
    private const ZNAKI = 'aąbcćdeęfghijklłmnńoóprsśtuwyzźż'
        .'AĄBCĆDEĘFGHIJKLŁMNŃOÓPRSŚTUWYZŹŻ'
        .'qvxQVX0123456789'
        .'.,:;!?()[]-/%&@#*+=\'"'
        .'„”‚’«»–—…°×€§';

    public function test_kazdy_polski_znak_ma_deklaracje_inter(): void
    {
        $zakresy = $this->zakresyInter();
        $this->assertNotEmpty($zakresy, 'Nie znaleziono @font-face „Inter Variable" z unicode-range.');

        $braki = [];
        foreach (mb_str_split(self::ZNAKI) as $znak) {
            $kod = mb_ord($znak);
            $pokryty = false;
            foreach ($zakresy as [$od, $do]) {
                if ($kod >= $od && $kod <= $do) {
                    $pokryty = true;
                    break;
                }
            }
            if (! $pokryty) {
                $braki[] = sprintf('%s (U+%04X)', $znak, $kod);
            }
        }

        $this->assertSame([], $braki, 'Poza podzbiorem Inter: '.implode(', ', $braki));
    }

    public function test_kazda_deklaracja_wskazuje_istniejacy_plik_z_osia_wght(): void
    {
        foreach ($this->blokiInter() as $blok) {
            $this->assertMatchesRegularExpression('/font-weight:\s*100 900;/', $blok);
            $this->assertMatchesRegularExpression('/font-display:\s*swap;/', $blok);
            $this->assertSame(1, preg_match('/url\("\.\.\/fonts\/([^"]+\.woff2)"\)/', $blok, $plik));
            $this->assertFileExists(resource_path('fonts/'.$plik[1]));
        }
    }

    /** @return list<array{int, int}> */
    private function zakresyInter(): array
    {
        $zakresy = [];
        foreach ($this->blokiInter() as $blok) {
            if (preg_match('/unicode-range:\s*([^;]+);/', $blok, $pole) !== 1) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', trim($pole[1])) as $wpis) {
                if (preg_match('/^U\+([0-9A-F]+)(?:-([0-9A-F]+))?$/i', $wpis, $m) === 1) {
                    $zakresy[] = [hexdec($m[1]), hexdec($m[2] ?? $m[1])];
                }
            }
        }

        return $zakresy;
    }

    /** @return list<string> */
    private function blokiInter(): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/fonts.css')));
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $bloki);

        return array_values(array_filter(
            $bloki[1],
            fn (string $blok): bool => str_contains($blok, '"Inter Variable"'),
        ));
    }
}
