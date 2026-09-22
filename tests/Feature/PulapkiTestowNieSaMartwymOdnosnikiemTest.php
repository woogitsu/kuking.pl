<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `AGENTS.md` wskazuje na `docs/PULAPKI_TESTOW.md`, a ten plik wskazuje na
 * konkretne testy jako wzorce do skopiowania. Odnośnik do pliku, którego nie
 * ma, jest gorszy niż brak odnośnika: czytający traci czas i traci zaufanie
 * do resztry dokumentu.
 *
 * TEN TEST POWSTAŁ Z WŁASNEJ POMYŁKI. Pierwsza wersja
 * `docs/PULAPKI_TESTOW.md` wskazywała na `DwuetapowaKodKopiaTest.php`
 * i `IdempotencjaZgloszeniaWyscigTest` bez ścieżki — pierwszy leżał na
 * niescalonej gałęzi i na `main` go nie było, drugi jest w podkatalogu
 * `Wyscigi/`. Czyli dokument o pułapkach testów sam wpadł w pułapkę numer 1
 * z własnej listy: „nie wierz, że coś jest na `main`, sprawdź".
 *
 * Dlatego ten test sprawdza WSZYSTKIE ścieżki `tests/...` wymienione w tym
 * dokumencie, a nie tylko te, o których pamiętam.
 */
class PulapkiTestowNieSaMartwymOdnosnikiemTest extends TestCase
{
    #[Test]
    public function test_agents_md_wskazuje_na_istniejacy_dokument(): void
    {
        $agents = (string) file_get_contents(base_path('AGENTS.md'));

        $this->assertStringContainsString(
            'docs/PULAPKI_TESTOW.md',
            $agents,
            'AGENTS.md przestał wskazywać na `docs/PULAPKI_TESTOW.md`. '.
            'Dokument bez odnośnika z jedynego źródła prawdy nie zostanie przeczytany.',
        );

        $this->assertFileExists(base_path('docs/PULAPKI_TESTOW.md'));
    }

    #[Test]
    public function test_kazdy_wskazany_test_naprawde_istnieje(): void
    {
        $dokument = (string) file_get_contents(base_path('docs/PULAPKI_TESTOW.md'));

        preg_match_all('#`(tests/[A-Za-z0-9_/]+\.php)`#', $dokument, $trafienia);

        $sciezki = array_unique($trafienia[1]);

        // Bez tej asercji test przechodziłby, gdyby wzorzec przestał cokolwiek
        // łapać — pułapka numer 2 z samego dokumentu.
        $this->assertGreaterThanOrEqual(
            3,
            count($sciezki),
            'W `docs/PULAPKI_TESTOW.md` nie znalazłem ścieżek do plików testowych. '.
            'Albo dokument je stracił, albo wzorzec w tym teście przestał je łapać — '.
            'jedno i drugie trzeba sprawdzić ręcznie.',
        );

        foreach ($sciezki as $sciezka) {
            $this->assertFileExists(
                base_path($sciezka),
                "`docs/PULAPKI_TESTOW.md` wskazuje na `{$sciezka}`, którego nie ma. ".
                'Plik mógł zostać przeniesiony albo nigdy nie wszedł na `main` '.
                '(np. leży na niescalonej gałęzi).',
            );
        }
    }

    /**
     * Katalogi wymieniane jako wzorce — osobno, bo `assertFileExists` na
     * katalogu przechodzi i nie sprawdza, czy cokolwiek w nim jest.
     */
    #[Test]
    public function test_wskazany_katalog_wyscigow_istnieje_i_nie_jest_pusty(): void
    {
        $dokument = (string) file_get_contents(base_path('docs/PULAPKI_TESTOW.md'));

        if (! str_contains($dokument, 'tests/Feature/Wyscigi/')) {
            $this->markTestSkipped('Dokument nie wskazuje już na katalog `tests/Feature/Wyscigi/`.');
        }

        $katalog = base_path('tests/Feature/Wyscigi');

        $this->assertDirectoryExists($katalog);
        $this->assertNotEmpty(
            glob($katalog.'/*Test.php') ?: [],
            'Katalog `tests/Feature/Wyscigi/` jest pusty, a dokument podaje go jako wzorzec.',
        );
    }
}
