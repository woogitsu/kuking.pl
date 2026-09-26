<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Każda komenda `railway config plan|apply` w dokumentacji podaje
 * `KUKING_WAIT_FOR_CI` jawnie.
 *
 * PO CO TO JEST
 * Od #1390 (PR #1595, scalony 26.09.2026) `.railway/railway.ts` rzuca
 * wyjątkiem, gdy `KUKING_WAIT_FOR_CI` nie jest dokładnie `true` albo
 * `false` — brak zmiennej nie może już po cichu zdejmować bramki „Wait for
 * CI”. Runbook i instrukcja przełączenia na trzy serwisy nadal pokazywały
 * gołe `railway config plan` / `apply`, a instrukcja #595 pisała wręcz
 * „zmienna tylko, jeśli krok 0.3”. Każdy PR z osobna był spójny; niespójne
 * było ich połączenie (audyt `docs/audyt/2026-09-26-PO-FALI.md`). Operator
 * idący po runbooku dostawał wyjątek w najbardziej nerwowym kroku.
 *
 * CO SPRAWDZA
 * Linie w blokach kodu (```) plików `docs/**.md` — poza `docs/audyt/`,
 * gdzie leżą zapisy historyczne — które WYWOŁUJĄ `railway config plan`
 * albo `apply` (opcjonalne przypisania `ZMIENNA=wartość` przed komendą).
 * Każda taka linia musi mieć `KUKING_WAIT_FOR_CI=true` albo `=false`.
 */
class KomendyIacWDokumentachPodajaBramkeCiTest extends TestCase
{
    public function test_kazda_komenda_iac_w_dokumentacji_podaje_bramke_ci(): void
    {
        $braki = [];

        foreach (File::allFiles(base_path('docs')) as $plik) {
            $sciezka = str_replace('\\', '/', $plik->getRelativePathname());

            if ($plik->getExtension() !== 'md' || str_starts_with($sciezka, 'audyt/')) {
                continue;
            }

            foreach (self::komendyBezBramki((string) file_get_contents($plik->getPathname())) as $linia => $tresc) {
                $braki[] = 'docs/'.$sciezka.':'.$linia.': '.$tresc;
            }
        }

        $this->assertSame([], $braki, implode("\n", [
            '`railway.ts` odmawia bez KUKING_WAIT_FOR_CI (#1390). Dopisz przed komendą',
            '`KUKING_WAIT_FOR_CI=true` (albo `=false`, gdy „Wait for CI” ma być wyłączone):',
            ...$braki,
        ]));
    }

    /**
     * KONTROLA DODATNIA: wykrywa gołą komendę i komendę z inną zmienną,
     * przepuszcza komendę z bramką, wzmiankę w tekście i komentarz.
     */
    public function test_parser_odroznia_komende_od_wzmianki(): void
    {
        $tekst = implode("\n", [
            'Uruchom `railway config plan` w terminalu.',
            '```bash',
            'railway link',
            'railway config plan',
            'KUKING_IAC_STAGING_ROZBITY=true railway config apply   # bez --yes',
            'KUKING_WAIT_FOR_CI=true railway config plan',
            'KUKING_WAIT_FOR_CI=false KUKING_IAC_STAGING_ROZBITY=true railway config apply',
            '# railway config apply — komentarz, nie komenda',
            '```',
            'railway config apply poza blokiem kodu',
        ]);

        $this->assertSame([
            4 => 'railway config plan',
            5 => 'KUKING_IAC_STAGING_ROZBITY=true railway config apply   # bez --yes',
        ], self::komendyBezBramki($tekst));
    }

    /**
     * @return array<int, string> numer linii => treść
     */
    private static function komendyBezBramki(string $tresc): array
    {
        $wynik = [];
        $wBloku = false;

        foreach (explode("\n", $tresc) as $i => $wiersz) {
            if (str_starts_with(ltrim($wiersz), '```')) {
                $wBloku = ! $wBloku;

                continue;
            }

            if (! $wBloku) {
                continue;
            }

            if (preg_match('/^\s*((?:[A-Z][A-Z0-9_]*=\S*\s+)*)railway\s+config\s+(plan|apply)\b/', $wiersz, $m) !== 1) {
                continue;
            }

            if (preg_match('/(^|\s)KUKING_WAIT_FOR_CI=(true|false)\s/', $m[1]) !== 1) {
                $wynik[$i + 1] = rtrim($wiersz);
            }
        }

        return $wynik;
    }
}
