<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2130, decyzja właściciela 29.09.2026 (D-330): dwa nakładające się wdrożenia
 * z RÓŻNYMI WERSJAMI danych słownika. Uczestnik B (wersja 2) importuje i staje
 * tuż przed zapisem znacznika, uczestnik A (wersja 1, np. z wycofanego
 * wdrożenia) rusza w tym czasie — jego odczyt znacznika sprzed blokady jest
 * pusty, więc bez ponownego sprawdzenia POD blokadą nadpisałby nowsze dane.
 *
 * Oczekiwanie: A po dostaniu blokady widzi zatwierdzony znacznik wersji 2
 * i nic nie zapisuje; tabele i znacznik zostają wersji 2.
 *
 * @bez-kontroli-dodatniej Test uruchamia prawdziwą klasę importu w osobnych procesach na PostgreSQL i porównuje zawartość tabel; kontrola dodatnia (bariera naprawdę zatrzymała uczestnika) jest w samym teście.
 */
#[Group('dwa-polaczenia')]
final class ImportOdzywczyWersjaPodBlokadaTest extends TestDwochPolaczen
{
    private const KLUCZ_TESTOWY = 'test_2130_wersja_dwa';

    /** @var list<string> */
    private array $katalogi = [];

    protected function tearDown(): void
    {
        // Baza wyścigów jest wspólna dla grupy, a ten test zatwierdza słowniki
        // naprawdę — zostawia po sobie czysty stan.
        try {
            $czysciciel = $this->nowePolaczenie();
            $czysciciel->exec('DELETE FROM aliasy_skladnikow');
            $czysciciel->exec('DELETE FROM miary_domowe');
            $czysciciel->exec('DELETE FROM skladniki_odzywcze');
            $czysciciel->exec("DELETE FROM cache WHERE key LIKE '%odzywcze:import:hash-plikow'");
        } finally {
            foreach ($this->katalogi as $katalog) {
                foreach (glob($katalog.'/*') ?: [] as $plik) {
                    @unlink($plik);
                }
                @rmdir($katalog);
            }
            parent::tearDown();
        }
    }

    #[Test]
    public function starszy_import_czekajacy_na_blokade_nie_nadpisuje_nowszej_wersji(): void
    {
        $starszy = $this->katalogPlikow(1);
        $nowszy = $this->katalogPlikow(2);
        $srodowisko = ['CACHE_STORE' => 'database'];

        $this->wyczysc();
        $this->assertSame(0, $this->liczba('skladniki_odzywcze'));
        $this->assertSame(0, $this->liczbaZnacznikow());

        $bariera = $this->nowePolaczenie();
        $this->assertTrue($this->prawda($this->odczytaj($bariera, 'SELECT pg_try_advisory_lock(2130, 1)')));

        try {
            // B (wersja 2) importuje i staje przed zapisem znacznika.
            $b = $this->wTle('importuj-odzywcze', ['katalog' => $nowszy, 'bariera' => '1'], $srodowisko);
            $this->czekajNaZablokowane(1);

            // KONTROLA DODATNIA: B stoi przed zapisem znacznika.
            $this->assertSame(0, $this->liczbaZnacznikow(), 'Bariera nie zatrzymała B przed zapisem znacznika.');

            // A (wersja 1) rusza: znacznika jeszcze nie ma, więc idzie po blokadę.
            $a = $this->wTle('importuj-odzywcze', ['katalog' => $starszy], $srodowisko);
            $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
            while (! $a->zakonczony() && $this->ilu() < 2 && microtime(true) < $koniec) {
                usleep(20_000);
            }
        } finally {
            $this->assertTrue($this->prawda($this->odczytaj($bariera, 'SELECT pg_advisory_unlock(2130, 1)')));
        }

        $wynikB = $b->wynik();
        $wynikA = $a->wynik();
        $this->assertTrue($wynikB['ok'], $wynikB['komunikat']);
        $this->assertTrue($wynikA['ok'], $wynikA['komunikat']);
        $this->assertFalse($wynikB['wartosc']['pominieto']);
        $this->assertTrue(
            $wynikA['wartosc']['pominieto'],
            'Starszy import (wersja 1) przeszedł po blokadzie mimo zatwierdzonej wersji 2 — nadpisał nowsze dane.',
        );

        $this->assertSame(1, $this->liczbaSkladnika(self::KLUCZ_TESTOWY), 'Dane wersji 2 zostały cofnięte przez starszy import.');
        $this->assertSame(1, $this->liczbaZnacznikow());

        // Trzecie uruchomienie na plikach wersji 2: szybka ścieżka.
        $trzeci = $this->wTle('importuj-odzywcze', ['katalog' => $nowszy], $srodowisko)->wynik();
        $this->assertTrue($trzeci['ok'], $trzeci['komunikat']);
        $this->assertTrue($trzeci['wartosc']['pominieto']);
    }

    private function wyczysc(): void
    {
        $czysciciel = $this->nowePolaczenie();
        $czysciciel->exec('DELETE FROM aliasy_skladnikow');
        $czysciciel->exec('DELETE FROM miary_domowe');
        $czysciciel->exec('DELETE FROM skladniki_odzywcze');
        $czysciciel->exec("DELETE FROM cache WHERE key LIKE '%odzywcze:import:hash-plikow'");
    }

    /** Katalog z kopią plików repozytorium i wpisem wersji; wersja 2 ma jeden składnik więcej. */
    private function katalogPlikow(int $wersja): string
    {
        $katalog = sys_get_temp_dir().'/kuking-odzywcze-2130w-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($katalog, 0700));
        $this->katalogi[] = $katalog;

        $zrodlo = base_path(ImportujWartosciOdzywcze::KATALOG);
        $skladniki = (string) file_get_contents($zrodlo.'/skladniki.csv');
        $this->assertNotSame('', $skladniki);
        if ($wersja === 2) {
            $skladniki = rtrim($skladniki, "\n")."\n".self::KLUCZ_TESTOWY.',Składnik testowy 2130 wersja dwa,alias2130wersjadwa,ciqual,0,,0,Test,10,1,1,1'."\n";
        }
        file_put_contents($katalog.'/skladniki.csv', $skladniki);
        copy($zrodlo.'/miary.csv', $katalog.'/miary.csv');
        file_put_contents($katalog.'/WERSJA', $wersja.' '.ImportujWartosciOdzywcze::hashDanych($katalog)."\n");

        return $katalog;
    }

    private function liczba(string $tabela): int
    {
        return (int) $this->odczytaj($this->obserwator, 'SELECT count(*) FROM '.$tabela);
    }

    private function liczbaSkladnika(string $klucz): int
    {
        $zapytanie = $this->obserwator->prepare('SELECT count(*) FROM skladniki_odzywcze WHERE klucz = ?');
        $zapytanie->execute([$klucz]);

        return (int) $zapytanie->fetchColumn();
    }

    private function liczbaZnacznikow(): int
    {
        return (int) $this->odczytaj($this->obserwator, "SELECT count(*) FROM cache WHERE key LIKE '%odzywcze:import:hash-plikow'");
    }

    private function prawda(mixed $wynik): bool
    {
        return filter_var($wynik, FILTER_VALIDATE_BOOLEAN);
    }
}
