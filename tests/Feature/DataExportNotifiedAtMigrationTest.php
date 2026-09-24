<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja `2026_09_23_180000_add_notified_at_to_data_exports` (issue #820):
 * kolumna, backfill paczek sprzed niej, CHECK i rollback.
 */
class DataExportNotifiedAtMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_23_180000_add_notified_at_to_data_exports.php');
    }

    private function wiersz(User $user, string $status, ?string $completedAt): string
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => $status,
            'completed_at' => $completedAt,
            'expires_at' => $completedAt === null ? null : now()->addDays(7),
        ]);

        return (string) $export->getKey();
    }

    public function test_paczki_gotowe_przed_migracja_nie_czekaja_na_list_ktorego_nikt_nie_wysle(): void
    {
        $basia = $this->user('basiamig');

        $this->migracja()->down();
        $this->assertFalse(Schema::hasColumn('data_exports', 'notified_at'));

        $gotowa = $this->wiersz($basia, DataExport::STATUS_READY, '2026-09-20 10:00:00+00');
        $wygasla = $this->wiersz($basia, DataExport::STATUS_EXPIRED, '2026-09-01 10:00:00+00');
        $nieudana = $this->wiersz($basia, DataExport::STATUS_FAILED, null);

        $this->migracja()->up();

        $this->assertTrue(DataExport::find($gotowa)->notified_at->equalTo(DataExport::find($gotowa)->completed_at));
        $this->assertNotNull(DataExport::find($wygasla)->notified_at);
        $this->assertNull(DataExport::find($nieudana)->notified_at);
    }

    public function test_list_bez_gotowej_paczki_odbija_sie_od_check(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basiacheck')->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('data_exports_notified_after_completed_check');

        DB::table('data_exports')->where('id', $export->getKey())->update(['notified_at' => now()]);
    }

    public function test_rollback_przechodzi_na_zapisanych_znacznikach_i_ponowne_up_je_odtwarza(): void
    {
        $basia = $this->user('basiarollback');
        $gotowa = $this->wiersz($basia, DataExport::STATUS_READY, '2026-09-22 10:00:00+00');
        DB::table('data_exports')->where('id', $gotowa)->update(['notified_at' => '2026-09-22 10:01:00+00']);

        $this->migracja()->down();
        $this->assertFalse(Schema::hasColumn('data_exports', 'notified_at'));

        // Drugie `down()` (tabela już bez kolumny) nie wybucha.
        $this->migracja()->down();

        $this->migracja()->up();

        // Po cyklu down/up paczka dalej uchodzi za powiadomioną —
        // rollback nie otwiera drogi do drugiego listu.
        $this->assertNotNull(DataExport::find($gotowa)->notified_at);
    }
}
