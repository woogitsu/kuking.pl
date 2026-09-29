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

    public function test_kazdy_znak_kontraktu_ma_glif_w_wygenerowanym_pliku(): void
    {
        // `unicode-range` mówi tylko, co przeglądarka ZAPYTA plik; „glify" z podzbior.json
        // to znaki, które plik naprawdę ma (cmap odczytany przez scripts/fonty-podzbior.py).
        $glify = [];
        foreach ($this->raport() as $plik => $wpis) {
            if (is_array($wpis)) {
                array_push($glify, ...$this->rozwinZakresy($wpis['glify']));
            }
        }

        $braki = [];
        foreach (mb_str_split(self::ZNAKI) as $znak) {
            if (! in_array(mb_ord($znak), $glify, true)) {
                $braki[] = sprintf('%s (U+%04X)', $znak, mb_ord($znak));
            }
        }

        $this->assertSame([], $braki, 'Brak glifu w podzbiorze Inter: '.implode(', ', $braki));
    }

    public function test_css_powtarza_zakresy_ze_skryptu_i_plik_zgadza_sie_z_raportem(): void
    {
        $raport = $this->raport();
        foreach ($this->blokiInter() as $blok) {
            preg_match('/url\("\.\.\/fonts\/([^"]+\.woff2)"\)/', $blok, $plik);
            preg_match('/unicode-range:\s*([^;]+);/', $blok, $pole);
            $wpis = $raport[$plik[1]] ?? null;
            $this->assertIsArray($wpis, "Brak {$plik[1]} w podzbior.json — uruchom scripts/fonty-podzbior.py.");
            $this->assertSame(
                $this->rozwinZakresy($wpis['unicode_range']),
                $this->rozwinZakresy($pole[1]),
                "unicode-range w fonts.css różni się od kontraktu dla {$plik[1]}.",
            );
            $this->assertSame(
                $wpis['bajty'],
                filesize(resource_path('fonts/'.$plik[1])),
                "{$plik[1]} nie pochodzi z ostatniego uruchomienia scripts/fonty-podzbior.py.",
            );
        }
    }

    public function test_podzbior_jest_wyraznie_mniejszy_niz_pelne_pliki(): void
    {
        // Przed #1000: 48 256 + 85 068 = 133 324 B. Budżet z zapasem na dodanie znaków.
        $this->assertLessThan(70_000, $this->raport()['razem_bajty']);
    }

    public function test_pelne_zrodla_nie_sa_serwowane(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/fonts.css')));
        $this->assertStringNotContainsString('zrodlo/', $css);
        $this->assertFileExists(resource_path('fonts/zrodlo/inter-latin-wght-normal.woff2'));
    }

    /** @return array<string, mixed> */
    private function raport(): array
    {
        return json_decode((string) file_get_contents(resource_path('fonts/podzbior.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function rozwinZakresy(string $lista): array
    {
        $kody = [];
        foreach (preg_split('/\s*,\s*/', trim($lista)) as $wpis) {
            if (preg_match('/^U\+([0-9A-F]+)(?:-([0-9A-F]+))?$/i', $wpis, $m) === 1) {
                array_push($kody, ...range(hexdec($m[1]), hexdec($m[2] ?? $m[1])));
            }
        }

        return $kody;
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
