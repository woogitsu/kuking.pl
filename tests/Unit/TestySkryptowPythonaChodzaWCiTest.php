<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Każdy plik `test_*.py` (i `*_test.py`) w `scripts/` i `docs/obciazenie/` jest uruchamiany w CI.
 *
 * CO SIĘ STAŁO
 * `scripts/test_podzial_kontroli.py` pilnuje, żeby żaden wpis kontroli
 * negatywnych nie wypadł z części CI — a sam nigdzie nie był uruchamiany.
 * Strażnik bez uruchomienia jest ozdobą: może być czerwony miesiącami,
 * a CI świeci zielono. Testy skryptów Pythona nie należą do PHPUnita, więc
 * żaden inny strażnik nie zauważy, że nowy plik nie ma kroku w workflow.
 *
 * CO TEST ROBI
 * Szuka plików `test_*.py` i `*_test.py` w `scripts/` i `docs/obciazenie/`
 * (rekurencyjnie; ta druga to test narzędzi przyrządu #605, `test_connection.py`,
 * na atrapach `psql`, bez bazy i sieci) i wymaga,
 * by nazwa pliku stała w wierszu, który nie jest komentarzem, w którymś
 * z workflowów (katalog `.github/workflows`). Komentarz nie liczy się:
 * „uruchamiane w CI" przy kroku, który już nie istnieje, byłoby fałszywym dowodem.
 *
 * TESTY POWŁOKI (`tests/skrypty/*.sh`) pilnuje inny strażnik: `scripts/kontrole-powloki.sh`
 * (LISTA albo POZA_LISTA z powodem) i `KontrolePowlokiLokalnieIWCiTest`.
 *
 * WYJĄTKI: tylko na liście `wyjatki()`, każdy z powodem. Wyjątek, który
 * przestał być potrzebny (plik zniknął albo trafił do CI), też jest błędem —
 * lista ma nie rosnąć w ciszy.
 *
 * Czego ten test NIE dowodzi: że wskazany krok faktycznie przechodzi w CI
 * (to robi sam krok), ani że jest w jobie, który biegnie na każdym PR-ze
 * (zakres joba to osobna decyzja przy kroku).
 */
final class TestySkryptowPythonaChodzaWCiTest extends TestCase
{
    /**
     * Ścieżka względem korzenia repozytorium => powód, dla którego plik nie chodzi w CI.
     * Dziś pusta: wszystkie testy skryptów mają krok w workflowie.
     *
     * Metoda, nie stała: pusta stała ma dla analizy typ `array{}`, a warunek
     * z nią byłby „zawsze fałszywy” — lista jest jednak po to, żeby rosła
     * (ten sam wzorzec co w `FormularzeZWalidacjaMajaNovalidateTest`).
     *
     * @return array<string, string>
     */
    private static function wyjatki(): array
    {
        return [];
    }

    /**
     * Katalogi (względem korzenia repozytorium) przeszukiwane pod kątem testów Pythona.
     *
     * @var list<string>
     */
    private const KATALOGI = ['scripts', 'docs/obciazenie'];

    public function test_kazdy_test_skryptu_pythona_ma_krok_w_workflowie(): void
    {
        $testy = self::plikiTestowPythona();

        // Wyszukiwarka, która nic nie znajduje, przepuszcza wszystko.
        $this->assertGreaterThanOrEqual(8, count($testy), 'Nie znaleziono testów Pythona w scripts/ i docs/obciazenie/ — strażnik świeciłby nad niczym. Czy zmieniono ich nazewnictwo?');

        $workflowy = self::tekstWorkflowowBezKomentarzy();
        $bezKroku = [];

        foreach ($testy as $sciezka) {
            if (array_key_exists($sciezka, self::wyjatki())) {
                continue;
            }

            if (! self::wystepuje($sciezka, $workflowy)) {
                $bezKroku[] = $sciezka;
            }
        }

        $this->assertSame([], $bezKroku, implode("\n", [
            'Test skryptu Pythona bez kroku w workflowie — nikt go nie uruchamia w CI, więc może być czerwony bez śladu.',
            'Dołóż go do istniejącego kroku/joba z testami skryptów w ci.yml, np.:',
            '  run: python3 -m unittest discover -s <katalog> -p <plik>.py -v',
            'Albo dopisz do wyjatki() w tym teście z powodem (np. zależność, której CI nie ma).',
            'Bez kroku:',
            ...$bezKroku,
        ]));
    }

    public function test_wyjatki_dotycza_istniejacych_plikow_bez_kroku_w_ci(): void
    {
        $testy = self::plikiTestowPythona();
        $workflowy = self::tekstWorkflowowBezKomentarzy();

        foreach (self::wyjatki() as $sciezka => $powod) {
            $this->assertNotSame('', trim($powod), "Wyjątek {$sciezka} bez powodu.");
            $this->assertContains($sciezka, $testy, "Wyjątek {$sciezka} wskazuje plik, którego nie ma. Usuń go z wyjatki().");
            $this->assertFalse(
                self::wystepuje($sciezka, $workflowy),
                "Wyjątek {$sciezka} jest już uruchamiany w CI. Usuń go z wyjatki().",
            );
        }

        $this->addToAssertionCount(1);
    }

    private static function wystepuje(string $sciezka, string $tekst): bool
    {
        return preg_match('/(?<![\w.-])'.preg_quote(basename($sciezka), '/').'(?![\w-])/', $tekst) === 1;
    }

    /**
     * @return list<string>
     */
    private static function plikiTestowPythona(): array
    {
        $korzen = dirname(__DIR__, 2);
        $wynik = [];

        foreach (self::KATALOGI as $katalog) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($korzen.'/'.$katalog, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $plik) {
                if (preg_match('/^test_.+\.py$|^.+_test\.py$/', $plik->getFilename()) === 1) {
                    $wynik[] = substr($plik->getPathname(), strlen($korzen) + 1);
                }
            }
        }

        sort($wynik);

        return $wynik;
    }

    private static function tekstWorkflowowBezKomentarzy(): string
    {
        $tekst = '';

        foreach (glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [] as $plik) {
            foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($plik)) ?: [] as $wiersz) {
                if (! str_starts_with(ltrim($wiersz), '#')) {
                    $tekst .= $wiersz."\n";
                }
            }
        }

        return $tekst;
    }
}
