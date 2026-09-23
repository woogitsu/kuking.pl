<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie kasuje po cichu rozstrzygniętych oznaczeń automatu.
 *
 * DLACZEGO TO BOLI AKURAT TU
 * Oznaczenie, które moderator już rozpatrzył, niesie POWÓD, dla którego coś
 * ukrył albo kogoś zawiesił — i jest jedynym miejscem, w którym da się to
 * odtworzyć przy odwołaniu (DSA art. 17). Oznaczenie jeszcze OTWARTE nie
 * niesie niczego: nikt przy nim niczego nie postanowił, a automat postawi je
 * z powrotem, gdy migracja wróci. Dlatego `down()` traktuje te dwa przypadki
 * inaczej, a ten test jest wykonaniem tego planu, nie jego opisem.
 *
 * Sprawdzamy też, że strażnik stoi PRZED pierwszym `DELETE`. Odmowa, która
 * i tak zdążyła coś skasować, jest tylko ładniejszym komunikatem o stracie.
 */
class CofniecieMigracjiSygnalowAutomatuTest extends TestCase
{
    use RefreshDatabase;

    private const FURTKA = 'KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU';

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_09_400000_sygnaly_automatu_w_zgloszeniach.php',
        );
    }

    private function oznaczenie(string $status, string $celId): Report
    {
        return Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'post',
            'target_id' => $celId,
            'reason' => 'automat_wzorzec',
            'details' => 'Automat oznaczył tę treść do przeglądu.',
            'status' => $status,
            // `reports_resolution_complete_check` (#997): stan końcowy ma datę.
            'resolved_at' => in_array($status, [Report::STATUS_RESOLVED, Report::STATUS_REJECTED], true) ? now() : null,
        ]);
    }

    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['reports', 'autor_tresci_id'],
        ) !== [];
    }

    public function test_cofniecie_odmawia_gdy_sa_rozstrzygniete_oznaczenia(): void
    {
        $otwarte = $this->oznaczenie(Report::STATUS_OPEN, (string) Str::uuid());
        $zamkniete = $this->oznaczenie(Report::STATUS_REJECTED, (string) Str::uuid());

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

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało rozstrzygnięte oznaczenia automatu.');

        // Komunikat ma mówić ILE się straci i CO ZROBIĆ. JEDNO oznaczenie,
        // nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest zdaniem poprawnym po polsku.
        $this->assertStringContainsString(
            'Liczba rozstrzygniętych oznaczeń automatu w bazie: 1.',
            $odmowa->getMessage(),
        );

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('jest 1 rozstrzygniętych', $odmowa->getMessage());

        $this->assertStringContainsString('kopię tabeli', $odmowa->getMessage());
        $this->assertStringContainsString(self::FURTKA, $odmowa->getMessage());

        // ASERCJA KONTROLNA — strażnik stoi PRZED kasowaniem, więc nie zginęło
        // nic, także oznaczenie otwarte.
        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumna `autor_tresci_id` zniknęła mimo odmowy.');
        $this->assertDatabaseHas('reports', ['id' => $zamkniete->getKey()]);
        $this->assertDatabaseHas('reports', ['id' => $otwarte->getKey()]);
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        $otwarte = $this->oznaczenie(Report::STATUS_OPEN, (string) Str::uuid());

        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumny nie było już przed cofnięciem.');

        $this->migracja()->down();

        $this->assertFalse($this->kolumnaIstnieje(), 'Cofnięcie nie zdjęło kolumny `autor_tresci_id`.');
        $this->assertDatabaseMissing('reports', ['id' => $otwarte->getKey()]);
    }

    public function test_furtka_ze_srodowiska_procesu_przepuszcza_cofniecie(): void
    {
        $this->oznaczenie(Report::STATUS_RESOLVED, (string) Str::uuid());

        // Furtkę podaje się w środowisku procesu:
        // `KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU=1 php artisan migrate:rollback`.
        // Strażnik czyta ją przez `getenv()`, tak samo jak pozostałe migracje
        // z furtką w tym repozytorium.
        $poprzednia = getenv(self::FURTKA);
        putenv(self::FURTKA.'=1');

        try {
            $this->migracja()->down();

            $this->assertFalse(
                $this->kolumnaIstnieje(),
                'Furtka nie zadziałała: kolumna została mimo jawnej zgody.',
            );
        } finally {
            if ($poprzednia === false) {
                putenv(self::FURTKA);
            } else {
                putenv(self::FURTKA.'='.$poprzednia);
            }
        }
    }
}
