<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #1365: `ready` bez metadanych paczki nie istnieje ani w bazie
 * (`data_exports_ready_complete_check`), ani w oczach modelu
 * (`DataExport::isDownloadable()`). Wcześniej baza przyjmowała `ready` bez
 * dysku, klucza, rozmiaru i daty ukończenia, a ekran ustawień pokazywał go
 * jako gotową paczkę, której pobranie kończyło się 404.
 */
class GotowyEksportMaKompletMetadanychTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK = 'data_exports_ready_complete_check';

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_24_200000_require_complete_ready_data_exports.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function komplet(): array
    {
        return [
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/basia.zip',
            'bytes' => 1234,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    private function zapisz(User $user, array $kolumny): string
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DB::table('data_exports')->where('id', $export->getKey())->update($kolumny);

        return (string) $export->getKey();
    }

    /**
     * Każdy wiersz na osobnym koncie — `data_exports_one_active_per_user`
     * pozwala na jeden aktywny eksport na konto.
     */
    private function czyOdrzucone(array $kolumny): bool
    {
        $user = $this->user('basia'.bin2hex(random_bytes(4)));

        try {
            // Punkt zapisu: odrzucony UPDATE nie zatruwa transakcji testu.
            DB::transaction(fn () => $this->zapisz($user, $kolumny));
        } catch (QueryException $e) {
            $this->assertStringContainsString(self::CHECK, $e->getMessage());

            return true;
        }

        return false;
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function brakujacePola(): array
    {
        return [
            'bez dysku' => ['disk', null],
            'pusty dysk' => ['disk', ''],
            'bez klucza' => ['object_key', null],
            'pusty klucz' => ['object_key', ''],
            'bez rozmiaru' => ['bytes', null],
            'zerowy rozmiar' => ['bytes', 0],
            'bez daty ukończenia' => ['completed_at', null],
            'bez terminu' => ['expires_at', null],
        ];
    }

    #[DataProvider('brakujacePola')]
    public function test_ready_bez_jednego_pola_jest_odrzucone(string $pole, mixed $wartosc): void
    {
        // KONTROLA DODATNIA: komplet przechodzi — odrzucenie niżej jest
        // skutkiem brakującego pola, nie czegoś innego w wierszu.
        $this->assertFalse($this->czyOdrzucone($this->komplet()));

        $this->assertTrue(
            $this->czyOdrzucone([$pole => $wartosc] + $this->komplet()),
            "Baza przyjęła `ready` z {$pole} = ".var_export($wartosc, true).'.',
        );
    }

    public function test_pozostale_stany_i_uniewazniona_gotowa_paczka_pozostaja_mozliwe(): void
    {
        $this->assertFalse($this->czyOdrzucone(['status' => DataExport::STATUS_PROCESSING]));
        $this->assertFalse($this->czyOdrzucone(['status' => DataExport::STATUS_FAILED, 'failure_reason' => DataExport::REASON_UNKNOWN]));

        // `expired` po udanym kasowaniu (adres usunięty) i po nieudanym
        // (adres zachowany do ponowienia przez `kuking:sprzataj-eksporty`).
        $this->assertFalse($this->czyOdrzucone(['status' => DataExport::STATUS_EXPIRED, 'expires_at' => now()->subDay()]));
        $this->assertFalse($this->czyOdrzucone([
            'status' => DataExport::STATUS_EXPIRED, 'disk' => 'local', 'object_key' => 'eksporty/stara.zip',
            'bytes' => 1234, 'expires_at' => now()->subDay(),
        ]));

        // Wymazanie konta unieważnia paczkę sprzed sekundy, przestawiając
        // `expires_at` w przeszłość — także przed `completed_at`.
        $this->assertFalse($this->czyOdrzucone([
            'completed_at' => now(), 'expires_at' => now()->subSecond(),
        ] + $this->komplet()));

        $this->assertFalse($this->czyOdrzucone(['status' => DataExport::STATUS_QUEUED]));
    }

    public function test_model_nie_uznaje_niekompletnej_paczki_za_dostepna(): void
    {
        $komplet = new DataExport($this->komplet());
        $this->assertTrue($komplet->isDownloadable());

        foreach (self::brakujacePola() as $opis => [$pole, $wartosc]) {
            if ($pole === 'expires_at') {
                continue;
            }

            $this->assertFalse(
                (new DataExport([$pole => $wartosc] + $this->komplet()))->isDownloadable(),
                "Paczka {$opis} uchodzi za dostępną.",
            );
        }
    }

    public function test_migracja_odmawia_przy_niekompletnym_ready_i_nie_zgaduje(): void
    {
        $basia = $this->user('basiaaudyt');

        $this->migracja()->down();
        $niekompletny = $this->zapisz($basia, ['bytes' => null, 'completed_at' => null] + $this->komplet());

        try {
            $this->migracja()->up();
            $this->fail('Migracja przeszła mimo gotowego eksportu bez metadanych.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        // Nic nie zgadnięte, CHECK nie założony.
        $wiersz = DB::table('data_exports')->where('id', $niekompletny)->first();
        $this->assertSame(DataExport::STATUS_READY, $wiersz->status);
        $this->assertNull($wiersz->bytes);
        $this->assertFalse($this->jestCheck());

        // Po ręcznej naprawie opisanej w komunikacie migracja przechodzi.
        DB::table('data_exports')->where('id', $niekompletny)
            ->update(['status' => DataExport::STATUS_FAILED, 'failure_reason' => DataExport::REASON_UNKNOWN]);
        $this->migracja()->up();
        $this->assertTrue($this->jestCheck());
    }

    public function test_rollback_zdejmuje_check_i_ponowne_up_go_zaklada(): void
    {
        $gotowa = $this->zapisz($this->user('basiarollback'), $this->komplet());

        $this->migracja()->down();
        $this->assertFalse($this->jestCheck());

        // Drugie `down()` nie wybucha.
        $this->migracja()->down();

        $this->migracja()->up();
        $this->assertTrue($this->jestCheck());
        $this->assertSame(DataExport::STATUS_READY, DataExport::find($gotowa)->status);
    }

    private function jestCheck(): bool
    {
        return DB::table('pg_constraint')->where('conname', self::CHECK)->exists();
    }
}
