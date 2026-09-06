<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill `data_exports.failure_reason`: wolny tekst → zamknięty zbiór
 * kodów (audyt W7-07, migracja
 * `2026_09_06_210000_convert_data_export_failure_reason_to_codes`).
 *
 * Wiersze sprzed tej migracji trafiały tu przez `App\Models\DataExport::
 * create()` i stary `GenerateUserExport::reasonFor()` — dlatego symulujemy
 * je przez surowe `DB::table()`, tak jak naprawdę wyglądały w bazie, zamiast
 * przez model, który dziś już takich wartości nie zapisuje.
 */
class DataExportFailureReasonMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_06_210000_convert_data_export_failure_reason_to_codes.php',
        );
    }

    private function staryWiersz(User $user, ?string $failureReason): string
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_FAILED,
        ]);

        DB::table('data_exports')
            ->where('id', $export->getKey())
            ->update(['failure_reason' => $failureReason]);

        return (string) $export->getKey();
    }

    public function test_backfill_rozpoznaje_dwa_znane_literaly_a_reszte_wrzuca_do_worka(): void
    {
        $basia = $this->user('basia');

        $kontoNieIstnieje = $this->staryWiersz($basia, 'Konto nie istnieje.');
        $timeout = $this->staryWiersz($basia, 'Przygotowanie paczki przerwane (przekroczony limit czasu).');
        $stareWolneZdanie = $this->staryWiersz($basia,
            'Nie udało się przygotować paczki: SQLSTATE[42P01]: Undefined table "data_exports"',
        );
        $bezPowodu = $this->staryWiersz($basia, null);

        $this->migracja()->up();

        $this->assertSame(DataExport::REASON_ACCOUNT_MISSING, DataExport::find($kontoNieIstnieje)?->failure_reason);
        $this->assertSame(DataExport::REASON_TIMEOUT, DataExport::find($timeout)?->failure_reason);
        // Nie da się z samego zdania odtworzyć, w którym kroku joba padło —
        // trafia do worka, nie do zgadywania po treści.
        $this->assertSame(DataExport::REASON_UNKNOWN, DataExport::find($stareWolneZdanie)?->failure_reason);
        $this->assertNull(DataExport::find($bezPowodu)?->failure_reason);
    }

    public function test_backfill_nie_rusza_wierszy_ktore_juz_maja_znany_kod(): void
    {
        $basia = $this->user('basia');
        $jużZKodem = $this->staryWiersz($basia, DataExport::REASON_STORAGE);

        $this->migracja()->up();

        $this->assertSame(DataExport::REASON_STORAGE, DataExport::find($jużZKodem)?->failure_reason);
    }

    public function test_rollback_czysci_kody_do_null_bez_probowania_odtworzyc_stary_tekst(): void
    {
        $basia = $this->user('basia');
        $id = $this->staryWiersz($basia, DataExport::REASON_STORAGE);

        $this->migracja()->down();

        $this->assertNull(DataExport::find($id)?->failure_reason);
    }
}
