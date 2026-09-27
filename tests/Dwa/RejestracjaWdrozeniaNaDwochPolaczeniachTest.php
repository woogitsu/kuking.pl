<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dwa równoległe wdrożenia pod TĄ SAMĄ etykietą nie mogą dostać tego samego
 * numeru (issue #1932, D-318).
 *
 * SKĄD SIĘ BIERZE RYZYKO
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie` liczy numer jako
 * `MAX(numer) WHERE etykieta = ?) + 1`. Bez blokady dwa równoległe starty
 * (np. redeploy uruchomiony tuż po poprzednim, zanim ten pierwszy zdążył
 * zatwierdzić transakcję) przeczytałyby ten sam `MAX` i policzyły ten sam
 * numer — dokładnie to samo ryzyko, co „pierwszy zapis do zeszytu" (D-070,
 * `ZbiorczyZapisNaDwochPolaczeniachTest`), tylko na innej tabeli.
 *
 * JAK WYMUSZAMY PRAWDZIWE ZDERZENIE
 * Numeracja nie chroni się blokadą WIERSZA (nie ma jeszcze żadnego wiersza
 * do zablokowania przy pierwszym wdrożeniu — ten sam problem, który D-070
 * opisuje dla `FOR UPDATE` na nieistniejącej partii), tylko blokadą
 * DORADCZĄ na kluczu policzonym z etykiety
 * (`pg_advisory_xact_lock(hashtext($etykieta))`). Test bierze TĘ SAMĄ
 * blokadę na WŁASNYM połączeniu, zanim uruchomi którykolwiek proces —
 * każda próba rejestracji wewnątrz akcji staje więc w kolejce za barierą
 * testu, dokładnie tak jak stałaby za drugim, równoległym wdrożeniem.
 * Dopiero zwolnienie bariery puszcza oba procesy naraz, do tej samej
 * blokady — a kolejka PostgreSQL do blokady doradczej jest obsługiwana
 * W KOLEJNOŚCI ZGŁOSZEŃ, więc przeplot jest powtarzalny.
 */
#[Group('dwa-polaczenia')]
final class RejestracjaWdrozeniaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /**
     * LOSOWA ETYKIETA NA TEST, tym samym powodem co losowe nazwy kont
     * w klasie bazowej (`konto()`): ta grupa NIE UŻYWA `RefreshDatabase`
     * (dane zatwierdzają się naprawdę, `TestDwochPolaczen`), więc wiersze
     * `wdrozenia` z poprzedniego przebiegu ZOSTAJĄ w bazie wyścigów. Stała
     * etykieta zderzyłaby się z nimi na `MAX(numer)` i test mierzyłby
     * numerację po cudzym, wcześniejszym przebiegu, a nie „od 1" — dokładnie
     * ta sama pułapka, dla której `konto()` losuje nazwę użytkownika.
     */
    private function etykieta(): string
    {
        return 'Alfa 0.'.random_int(100000, 999999);
    }

    /**
     * `commit` jest `UNIQUE` w CAŁEJ tabeli, niezależnie od etykiety —
     * losowa etykieta wyżej nie chroni przed zderzeniem, gdyby ten sam
     * (stały) commit trafił do bazy wyścigów w dwóch przebiegach z rzędu.
     */
    private function commit(): string
    {
        return bin2hex(random_bytes(20));
    }

    public function test_dwa_rownolegle_wdrozenia_dostaja_dwa_rozne_numery(): void
    {
        $etykieta = $this->etykieta();
        $bariera = $this->barieraNaEtykiecie($etykieta);

        $pierwszy = $this->wTle('zarejestruj-wdrozenie', [
            'commit' => $this->commit(),
            'etykieta' => $etykieta,
        ]);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('zarejestruj-wdrozenie', [
            'commit' => $this->commit(),
            'etykieta' => $etykieta,
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsza rejestracja wdrożenia');
        $this->assertBezZakleszczenia($wynikDrugiego, 'druga rejestracja wdrożenia');
        $this->assertTrue($wynikPierwszego['ok'], 'Pierwsza rejestracja padła: '.$wynikPierwszego['komunikat']);
        $this->assertTrue($wynikDrugiego['ok'], 'Druga rejestracja padła: '.$wynikDrugiego['komunikat']);

        $numery = [(int) $wynikPierwszego['wartosc'], (int) $wynikDrugiego['wartosc']];
        sort($numery);

        $this->assertSame(
            [1, 2],
            $numery,
            'Dwa równoległe wdrożenia pod tą samą etykietą dostały numery '
            .json_encode($numery).' — powinny dostać DWA RÓŻNE kolejne numery, nie ten sam.',
        );

        // Kontrola dodatnia: oba wiersze naprawdę doszły do bazy, z komitami
        // takimi, jakie podał każdy proces — a nie np. dwa wiersze jednego
        // procesu, co dałoby ten sam wynik z powodu zupełnie innej usterki.
        $this->assertSame(2, DB::table('wdrozenia')->where('etykieta', $etykieta)->count());
        $this->assertSame(
            [1, 2],
            DB::table('wdrozenia')->where('etykieta', $etykieta)->orderBy('numer')->pluck('numer')->all(),
        );
    }

    public function test_ten_sam_commit_zarejestrowany_rownolegle_dwa_razy_nie_zuzywa_dwoch_numerow(): void
    {
        // Redeploy bez zmiany kodu, uruchomiony niemal jednocześnie z
        // poprzednim krokiem tego samego wdrożenia (retry po chwilowym
        // błędzie sieci) — IDEMPOTENCJA po stronie `commit`, nie tylko
        // bezpieczeństwo numeracji.
        $etykieta = $this->etykieta();
        $bariera = $this->barieraNaEtykiecie($etykieta);
        $commit = $this->commit();

        $pierwszy = $this->wTle('zarejestruj-wdrozenie', ['commit' => $commit, 'etykieta' => $etykieta]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('zarejestruj-wdrozenie', ['commit' => $commit, 'etykieta' => $etykieta]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsza rejestracja tego samego commita');
        $this->assertBezZakleszczenia($wynikDrugiego, 'druga rejestracja tego samego commita');
        $this->assertTrue($wynikPierwszego['ok'], $wynikPierwszego['komunikat']);
        $this->assertTrue($wynikDrugiego['ok'], $wynikDrugiego['komunikat']);

        // OBA procesy mają zwrócić TEN SAM numer — drugi ma odnaleźć
        // istniejący wiersz, a nie policzyć nowy.
        $this->assertSame($wynikPierwszego['wartosc'], $wynikDrugiego['wartosc']);
        $this->assertSame(1, DB::table('wdrozenia')->where('commit', $commit)->count());
    }

    /**
     * Bierze `pg_advisory_xact_lock(hashtext($etykieta))` na osobnym
     * połączeniu — DOKŁADNIE tę blokadę i DOKŁADNIE ten klucz, których
     * `ZarejestrujWdrozenie::handle()` używa wewnątrz `DB::transaction()`.
     * Każda próba rejestracji pod tą samą etykietą staje więc w kolejce za
     * tą blokadą, tak jak stałaby za drugim, równoległym wdrożeniem.
     */
    private function barieraNaEtykiecie(string $etykieta): \PDO
    {
        $polaczenie = $this->nowePolaczenie();
        $polaczenie->beginTransaction();

        $zapytanie = $polaczenie->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $zapytanie->execute([$etykieta]);

        return $polaczenie;
    }
}
