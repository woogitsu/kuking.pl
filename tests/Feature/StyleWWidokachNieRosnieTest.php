<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Atrybuty `style="…"` w widokach nie mogą przybywać (issue #107).
 *
 * PO CO TO JEST
 * `script-src` jest już wymuszany bez `unsafe-inline` — wstrzyknięty skrypt
 * nie wykona się w ogóle (issue #12). Jedyną dyrektywą, która nadal wymaga
 * `unsafe-inline`, jest `style-src`, a wymaga go z jednego powodu: w widokach
 * zostały atrybuty `style="…"`. Nonce ich NIE ratuje — działa na element
 * `<style>`, a nie na atrybut `style`.
 *
 * DLACZEGO STRAŻNIK, A NIE „POSPRZĄTAMY TO KIEDYŚ"
 * Sprzątanie trwa dłużej niż jeden PR, a nowy widok powstaje przez skopiowanie
 * istniejącego. Bez tego testu praca cofałaby się szybciej, niż postępuje —
 * i nigdy nie doszlibyśmy do dnia, w którym da się usunąć `unsafe-inline`.
 *
 * Test pilnuje TYLKO kierunku: liczba ma spadać. Gdy spadnie do zera, zdejmij
 * `unsafe-inline` ze `style-src` w `ApplySecurityHeaders` i zamień ten test
 * na `assertSame(0, …)`.
 *
 * POCZTA JEST WYŁĄCZONA I TO NIE JEST WYJĄTEK „NA SKRÓTY"
 * Klienty pocztowe nie czytają arkuszy stylów — w e-mailu styl MUSI być
 * inline. CSP nie dotyczy poczty w ogóle, więc `resources/views/mail/**`
 * nie ma z tym problemem nic wspólnego.
 */
class StyleWWidokachNieRosnieTest extends TestCase
{
    /**
     * Stan na dzień wprowadzenia strażnika. Tę liczbę wolno WYŁĄCZNIE
     * obniżać — razem z prawdziwym sprzątaniem widoków.
     */
    private const LIMIT = 127;

    public function test_liczba_atrybutow_style_nie_rosnie(): void
    {
        $ile = 0;
        $najgorsze = [];

        foreach ($this->widoki() as $sciezka) {
            $tresc = (string) file_get_contents($sciezka);
            $wPliku = substr_count($tresc, ' style="');

            if ($wPliku > 0) {
                $ile += $wPliku;
                $najgorsze[$sciezka] = $wPliku;
            }
        }

        arsort($najgorsze);

        $this->assertLessThanOrEqual(
            self::LIMIT,
            $ile,
            "Atrybutów style=\"…\" w widokach jest {$ile}, a limit to ".self::LIMIT.".\n"
            ."Każdy z nich wymusza `unsafe-inline` w style-src — czyli trzyma otwartą ostatnią\n"
            ."furtkę polityki bezpieczeństwa (issue #107). Zapisz odstęp klasą (`mt-6`, `mb-5`),\n"
            ."a nie atrybutem.\n\n"
            .'Najwięcej w: '.implode(', ', array_map(
                static fn (string $p, int $n): string => basename($p)." ({$n})",
                array_slice(array_keys($najgorsze), 0, 5),
                array_slice(array_values($najgorsze), 0, 5),
            )),
        );
    }

    public function test_limit_nie_jest_ustawiony_z_zapasem(): void
    {
        // Strażnik strażnika. Limit ustawiony wysoko ponad stan faktyczny
        // przepuszczałby ciche przybywanie atrybutów — czyli robiłby dokładnie
        // to, przed czym ma chronić, wyglądając przy tym na działający.
        $ile = 0;

        foreach ($this->widoki() as $sciezka) {
            $ile += substr_count((string) file_get_contents($sciezka), ' style="');
        }

        $this->assertGreaterThanOrEqual(
            $ile,
            self::LIMIT,
            'Limit jest niższy niż stan faktyczny — popraw jedno albo drugie.',
        );

        $this->assertLessThanOrEqual(
            $ile + 5,
            self::LIMIT,
            'Limit stoi więcej niż pięć ponad stanem faktycznym. Obniż go do '.$ile.
            ' — inaczej przepuszcza ciche przybywanie atrybutów.',
        );
    }

    /** @return list<string> */
    private function widoki(): array
    {
        $katalog = resource_path('views');
        $pliki = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($katalog));

        foreach ($iterator as $plik) {
            if (! $plik->isFile() || ! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $sciezka = str_replace('\\', '/', $plik->getPathname());

            // Poczta zostaje — patrz opis klasy.
            if (str_contains($sciezka, '/views/mail/')) {
                continue;
            }

            $pliki[] = $sciezka;
        }

        return $pliki;
    }
}
