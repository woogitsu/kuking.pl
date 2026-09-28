<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\Exports\PrzejecieEksportu;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #2073 — kontrole dodatnie wspólnego przejęcia rekordu eksportu.
 *
 * Sam wyścig (dwa połączenia, kontrolowany przeplot) mierzy
 * `Tests\Dwa\SprzatanieEksportuKontraPonowienieTest`. Tu pilnujemy, żeby
 * blokada i rewalidacja nie zepsuły zwykłych dróg: osierocony plik nadal
 * znika, zwykły eksport nadal kończy się `ready` z plikiem, a sprzątanie
 * nie wykonuje operacji na rekordzie, który przestał być kandydatem.
 */
class SprzatanieEksportuPrzejecieRekorduTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);
    }

    public function test_osierocony_plik_nieudanej_proby_nadal_znika(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basia')->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);
        Storage::disk('local')->put($klucz, 'zip');
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_FAILED, 'failure_reason' => DataExport::REASON_UNKNOWN,
            'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('kuking:sprzataj-eksporty')
            ->doesntExpectOutputToContain('Pominięto')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($klucz);
        $this->assertSame(DataExport::STATUS_FAILED, $export->fresh()->status);
    }

    public function test_zwykly_eksport_konczy_sie_ready_z_plikiem_ktorego_sprzatanie_nie_rusza(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basia')->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        Storage::disk('local')->assertExists((string) $export->object_key);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        Storage::disk('local')->assertExists((string) $export->object_key);
        $this->assertSame(DataExport::STATUS_READY, $export->fresh()->status);
    }

    public function test_ponowienie_nieudanego_eksportu_konczy_sie_ready_z_plikiem(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basia')->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_FAILED, 'failure_reason' => DataExport::REASON_UNKNOWN,
            'updated_at' => now()->subHours(3),
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertNull($export->failure_reason);
        Storage::disk('local')->assertExists((string) $export->object_key);
    }

    public function test_sprzatanie_nie_wykonuje_operacji_na_rekordzie_ktory_przestal_byc_kandydatem(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basia')->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_PROCESSING, 'updated_at' => now()->subHours(3),
        ]);

        $wykonano = false;
        $przejety = PrzejecieEksportu::dlaSprzatania((string) $export->getKey(), function () use (&$wykonano): void {
            $wykonano = true;
        });

        $this->assertFalse($przejety);
        $this->assertFalse($wykonano, 'Sprzątanie wykonało operację na eksporcie, który jest znowu w toku.');

        // Kontrola dodatnia tej samej ścieżki: prawdziwy kandydat JEST przejmowany.
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_FAILED, 'updated_at' => now()->subHours(3),
        ]);

        $this->assertTrue(PrzejecieEksportu::dlaSprzatania((string) $export->getKey(), function () use (&$wykonano): void {
            $wykonano = true;
        }));
        $this->assertTrue($wykonano);
    }

    public function test_proba_nie_rusza_dla_eksportu_juz_gotowego(): void
    {
        $export = DataExport::create(['user_id' => $this->user('basia')->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);
        Storage::disk('local')->put($klucz, 'zip');
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_READY, 'disk' => 'local', 'object_key' => $klucz,
            'bytes' => 3, 'completed_at' => now(), 'expires_at' => now()->addDays(7),
        ]);

        // Model przeczytany wcześniej, jeszcze jako `queued`.
        $this->assertFalse(PrzejecieEksportu::dlaProby($export));
        $this->assertSame(DataExport::STATUS_READY, $export->fresh()->status);
    }
}
