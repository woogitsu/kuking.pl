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
 * DWA KATALOGI SĄ WYŁĄCZONE I ŻADEN Z NICH NIE JEST WYJĄTKIEM „NA SKRÓTY"
 *
 * `resources/views/mail/**` — klienty pocztowe nie czytają arkuszy stylów,
 * w e-mailu styl MUSI być inline. CSP nie dotyczy poczty w ogóle.
 *
 * `resources/views/exports/**` — paczka z danymi (RODO) to pliki HTML, które
 * człowiek otwiera Z DYSKU, we własnej przeglądarce, bez serwera. Nie ma tam
 * żadnych nagłówków, więc nie ma czego naruszać. Te widoki mają własny arkusz
 * (`exports/styles.blade.php`) i nie znają klas Tailwinda — zamiana stylu na
 * klasę zabrałaby im wygląd, nie poprawiając niczego.
 *
 * CZEGO NIE WYŁĄCZAMY, CHOĆ KUSI
 * `errors/_prosty.blade.php` (strony 500 i 503) ma style inline celowo — ma
 * działać, gdy arkusz się nie zbuduje. Ale ta strona idzie po HTTP, więc CSP
 * JĄ OBEJMUJE i po zdjęciu `unsafe-inline` wyrenderuje się bez stylów.
 * Rozwiązaniem będzie przeniesienie jej stylów do jednego bloku
 * `<style nonce="…">` w tym samym pliku — nadal bez zewnętrznego arkusza,
 * ale zgodnie z polityką. Dlatego zostaje policzona.
 */
class StyleWWidokachNieRosnieTest extends TestCase
{
    /**
     * Stan na dzień wprowadzenia strażnika. Tę liczbę wolno WYŁĄCZNIE
     * obniżać — razem z prawdziwym sprzątaniem widoków.
     */
    private const LIMIT = 40;

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

            // Poczta i paczka z danymi — patrz opis klasy.
            if (str_contains($sciezka, '/views/mail/') || str_contains($sciezka, '/views/exports/')) {
                continue;
            }

            $pliki[] = $sciezka;
        }

        return $pliki;
    }
}
