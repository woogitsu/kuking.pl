<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji dopuszczającej Facebooka nie zabiera po cichu wejścia
 * na konto (issue #259, D-088, D-098).
 *
 * DLACZEGO TA MIGRACJA WYMAGA STRAŻNIKA, CHOĆ NIE KASUJE ŻADNEJ TABELI
 * Cofnięcie zwęża CHECK z `('google', 'facebook')` do `('google')`. Samo
 * `ALTER TABLE` odbije się o bazę, gdy w tabeli leży choć jeden wiersz
 * Facebooka — i tu jest pułapka: najprostszym sposobem „naprawienia" tego
 * cofnięcia jest skasowanie tych wierszy po cichu. Wtedy osoba, która weszła
 * do Kuking kontem Facebooka i nigdy nie ustawiła hasła (w `password` leży
 * skrót wartości losowej, której nie zna nikt), traci jedyną drogę wejścia,
 * jaką zna — a `down()` prawie nigdy nie występuje sam: po nim idzie kolejny
 * `migrate`, CHECK wraca i NIE MA BŁĘDU DO ZAUWAŻENIA (D-088).
 *
 * Ten test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która
 * nie cofa się nigdy, blokowałaby staging i lokalne bazy bez powodu — i jest
 * błędem tej samej wagi w drugą stronę.
 */
class CofniecieMigracjiFacebookaOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_FACEBOOK';

    private const PLIK = 'migrations/2026_09_11_500000_dopusc_facebooka_w_tozsamosciach_zewnetrznych.php';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * Czy baza przyjmuje dziś `dostawca = 'facebook'`.
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO TEN INSERT SIEDZI W ZAGNIEŻDŻONEJ TRANSAKCJI
     * ────────────────────────────────────────────────────────────────────
     *
     * Sprawdzamy to jedynym uczciwym sposobem: próbą zapisu, która MA się
     * odbić o CHECK. I tu wchodzi zachowanie PostgreSQL, którego SQLite nie
     * ma — **nieudane zapytanie przerywa całą transakcję**. `RefreshDatabase`
     * trzyma cały test w jednej transakcji, więc po odbitym INSERT-cie każde
     * następne zapytanie, także zwykły `SELECT` w asercji, kończy się
     * `SQLSTATE[25P02] current transaction is aborted`. Złapanie wyjątku
     * w PHP tego nie cofa: `catch` uspokaja PHP, nie bazę.
     *
     * `DB::beginTransaction()` wewnątrz istniejącej transakcji zakłada
     * SAVEPOINT, a `DB::rollBack()` wraca do niego — i dopiero to przywraca
     * transakcji zdatność do dalszej pracy. Powrót do SAVEPOINT-a przy okazji
     * usuwa wstawiony wiersz, więc osobne `delete()` przestaje być potrzebne.
     *
     * To jest zarazem powód, dla którego `AGENTS.md` każe uruchamiać testy na
     * PostgreSQL, a nie na SQLite: na SQLite ten pomocnik działał i ukryłby
     * różnicę aż do CI.
     */
    private function facebookDopuszczony(): bool
    {
        $basia = $this->user();

        DB::beginTransaction();

        try {
            DB::table('tozsamosci_zewnetrzne')->insert([
                'user_id' => $basia->getKey(),
                'dostawca' => 'facebook',
                'identyfikator' => '10221234567890123',
                'connected_at' => now(),
            ]);
        } catch (\Throwable) {
            DB::rollBack();

            return false;
        }

        DB::rollBack();

        return true;
    }

    public function test_cofniecie_odmawia_gdy_ktos_wchodzi_kontem_facebooka(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook('10221234567890123');

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, a ta dziedziczy przez
        // `PHPUnit\Framework\Exception` po `RuntimeException`, więc
        // postawiona wewnątrz `try` wpadłaby do własnego `catch`.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało powiązanie z kontem Facebooka.');

        // Komunikat ma powiedzieć, ILU osób to dotyczy, CO ZROBIĆ ZAMIAST
        // TEGO i jak powiedzieć wprost „wiem, co robię". „Ktoś coś
        // straci" nie zatrzymuje nikogo o drugiej w nocy.
        //
        // JEDNO konto, nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest tu poprawna po polsku (D-132).
        $this->assertStringContainsString(
            'Liczba kont, których to dotyczy: 1.',
            $odmowa->getMessage(),
        );

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('dla 1 kont', $odmowa->getMessage());

        $this->assertStringContainsString('KUKING_WEJSCIE_FACEBOOK=false', $odmowa->getMessage());
        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());

        // NAJWAŻNIEJSZE: powiązanie nadal jest. Odmowa, która i tak zdążyła
        // skasować dane, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertSame('10221234567890123', (string) DB::table('tozsamosci_zewnetrzne')
            ->where('user_id', $basia->getKey())->value('identyfikator'));
    }

    public function test_powiazania_z_google_nie_maja_z_tym_nic_wspolnego(): void
    {
        // Konto z samym Google nie blokuje cofnięcia tej migracji — odmowa
        // musi być WĄSKA (D-088), inaczej wystarczyłby jeden człowiek
        // z Google, żeby zablokować rollback rzeczy, która go nie dotyczy.
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        $this->migracja()->down();

        $this->assertFalse($this->facebookDopuszczony(),
            'Po cofnięciu baza ma znów odbijać `facebook` — inaczej `down()` nic nie cofnął.');
        $this->assertDatabaseHas('tozsamosci_zewnetrzne', ['dostawca' => 'google']);

        $this->migracja()->up();
        $this->assertTrue($this->facebookDopuszczony());
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać. To jest kontrola
        // dodatnia dla strażnika wyżej.
        $this->migracja()->down();

        $this->assertFalse($this->facebookDopuszczony());

        // Migrujemy z powrotem, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        $this->migracja()->up();

        $this->assertTrue($this->facebookDopuszczony());
    }

    public function test_cofniecie_przechodzi_gdy_wlasciciel_powie_to_wprost(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook('10221234567890123');

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse($this->facebookDopuszczony(),
            'Świadoma zgoda ma przepuszczać cofnięcie — inaczej migracja jest nie do cofnięcia nigdy.');
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);

        $this->migracja()->up();
    }

    public function test_migracja_sprawdza_czy_wolno_zanim_cokolwiek_skasuje(): void
    {
        // Kolejność w `down()` ma znaczenie: gdyby kasowanie wierszy stało
        // przed sprawdzeniem, wyjątek leciałby już po utracie danych.
        // Czytamy kod, bo w działaniu tej różnicy nie widać — w obu wersjach
        // leci wyjątek.
        $kod = (string) file_get_contents(database_path(self::PLIK));

        $sprawdzenie = strpos($kod, '$this->ileKontFacebooka()');
        $kasowanie = strpos($kod, "->where('dostawca', 'facebook')->delete()");

        $this->assertNotFalse($sprawdzenie);
        $this->assertNotFalse($kasowanie);
        $this->assertLessThan($kasowanie, $sprawdzenie,
            'Sprawdzenie „czy wolno” musi stać PRZED skasowaniem powiązań.');
    }
}
