<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2130, komentarz z 28.09: dwa nakładające się wdrożenia z RÓŻNYMI plikami
 * słownika (różne SHA) importują wartości odżywcze równolegle.
 *
 * Przed poprawką `Cache::forever()` znacznika szedł PO zatwierdzeniu
 * transakcji, poza blokadą `pg_advisory_xact_lock(2130, 0)`. Uczestnik, który
 * zatwierdził pierwszy, mógł dopisać swój znacznik dopiero po tym, jak drugi
 * zdążył przepisać słowniki — i tabele zostawały opisane cudzym hashem i cudzym
 * odciskiem („dane jednej wersji, znacznik drugiej”).
 *
 * Przeplot odtwarzamy barierą: uczestnik B (nowsze pliki) staje tuż PRZED
 * zapisem znacznika, uczestnik A (starsze pliki) rusza w tym czasie. Potem
 * B jest puszczany. Kontrola końcowa: ten sam katalog, który zapisał ostatnie
 * dane (A), przy trzecim uruchomieniu ma pominąć pracę, czyli znacznik opisuje
 * DOKŁADNIE ostateczną zawartość tabel.
 *
 * Znacznik trafia do tabeli `cache` (proces potomny ma CACHE_STORE=database,
 * jak produkcja), więc zapis w transakcji jest atomowy z trzema tabelami.
 *
 * @bez-kontroli-dodatniej Test uruchamia prawdziwą klasę importu w osobnych procesach na PostgreSQL i porównuje zawartość tabel oraz wynik trzeciego uruchomienia; kontrola dodatnia (bariera naprawdę zatrzymała uczestnika) jest w samym teście.
 */
#[Group('dwa-polaczenia')]
final class ImportOdzywczyZnacznikPodBlokadaTest extends TestDwochPolaczen
{
    private const KLUCZ_TESTOWY = 'test_2130_tylko_w_nowszych_plikach';

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
    public function znacznik_opisuje_ostateczna_zawartosc_tabel_mimo_wyscigu_dwoch_wersji_plikow(): void
    {
        $starszy = $this->katalogPlikow(false);
        $nowszy = $this->katalogPlikow(true);
        $srodowisko = ['CACHE_STORE' => 'database'];

        // Baza wyścigów jest wspólna dla grupy — zaczynamy od czystego stanu
        // (poprzedni przebieg mógł zostawić słowniki albo znacznik).
        $this->wyczysc();
        $this->assertSame(0, $this->liczba('skladniki_odzywcze'));
        $this->assertSame(0, $this->liczbaZnacznikow());

        $bariera = $this->nowePolaczenie();
        $this->assertTrue($this->prawda($this->odczytaj($bariera, 'SELECT pg_try_advisory_lock(2130, 1)')));

        try {
            // B (nowsze pliki) importuje i staje przed zapisem znacznika.
            $b = $this->wTle('importuj-odzywcze', ['katalog' => $nowszy, 'bariera' => '1'], $srodowisko);
            $this->czekajNaZablokowane(1);

            // KONTROLA DODATNIA: B naprawdę stoi przed zapisem znacznika.
            $this->assertSame(
                0,
                $this->liczbaZnacznikow(),
                'B nie powinien jeszcze mieć widocznego znacznika — bariera nie zatrzymała go przed zapisem.',
            );

            // A (starsze pliki) rusza w tym czasie. W poprawionym kodzie staje
            // w kolejce po blokadę trzymaną przez B; w starym kończy całość.
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
        $this->assertFalse($wynikA['wartosc']['pominieto']);
        $this->assertFalse($wynikB['wartosc']['pominieto']);

        // Ostatni zapis tabel należy do A (starsze pliki): dodatkowego składnika
        // z nowszych plików już nie ma. To jest kontrola dodatnia scenariusza.
        $this->assertSame(0, $this->liczbaSkladnika(self::KLUCZ_TESTOWY), 'Ostatnim pisał A — składnika z nowszych plików już nie ma.');

        // Trzecie uruchomienie na plikach A: znacznik ma opisywać tabele.
        $trzeci = $this->wTle('importuj-odzywcze', ['katalog' => $starszy], $srodowisko)->wynik();
        $this->assertTrue($trzeci['ok'], $trzeci['komunikat']);
        $this->assertTrue(
            $trzeci['wartosc']['pominieto'],
            'Znacznik nie opisuje ostatniej zawartości tabel: dane zapisał uczestnik A, a znacznik zapisał B '
            .'(zapis znacznika poza blokadą, po zatwierdzeniu transakcji — #2130).',
        );
        $this->assertSame(1, $this->liczbaZnacznikow());
    }

    private function wyczysc(): void
    {
        $czysciciel = $this->nowePolaczenie();
        $czysciciel->exec('DELETE FROM aliasy_skladnikow');
        $czysciciel->exec('DELETE FROM miary_domowe');
        $czysciciel->exec('DELETE FROM skladniki_odzywcze');
        $czysciciel->exec("DELETE FROM cache WHERE key LIKE '%odzywcze:import:hash-plikow'");
    }

    /** Katalog z kopią plików repozytorium; wariant „nowszy” ma jeden składnik więcej. */
    private function katalogPlikow(bool $zDodatkowymSkladnikiem): string
    {
        $katalog = sys_get_temp_dir().'/kuking-odzywcze-2130-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($katalog, 0700));
        $this->katalogi[] = $katalog;

        $zrodlo = base_path(ImportujWartosciOdzywcze::KATALOG);
        $skladniki = (string) file_get_contents($zrodlo.'/skladniki.csv');
        $this->assertNotSame('', $skladniki);
        if ($zDodatkowymSkladnikiem) {
            $skladniki = rtrim($skladniki, "\n")."\n".self::KLUCZ_TESTOWY.',Składnik testowy 2130,alias2130testowy,ciqual,0,,0,Test,10,1,1,1'."\n";
        }
        file_put_contents($katalog.'/skladniki.csv', $skladniki);
        copy($zrodlo.'/miary.csv', $katalog.'/miary.csv');

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
