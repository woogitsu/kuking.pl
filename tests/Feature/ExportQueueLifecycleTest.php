<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\NotifyUserExportReady;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\PendingMail;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Prawdziwe zapisy kolejki i worker; bez transakcji otaczającej test. */
class ExportQueueLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default'),
            'kuking.exports.disk' => 'local']);
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        // Własne fixture usuwamy przed rollbackiem, który chroni znaczniki wysyłki.
        DB::table('data_exports')->delete();
        parent::tearDown();
    }

    public function test_odmowa_zapisu_joba_nie_zostawia_osieroconej_prosby(): void
    {
        $user = $this->user('eksportkolejka');
        DB::statement('ALTER TABLE jobs ADD CONSTRAINT odmowa_testowa CHECK (false) NOT VALID');
        $this->actingAs($user)->post(route('settings.data.export'))->assertServerError();
        $this->assertSame(0, DataExport::count(), 'Odmowa kolejki zostawiła osieroconą prośbę.');
        $this->assertSame(0, DB::table('jobs')->count());
        DB::statement('ALTER TABLE jobs DROP CONSTRAINT odmowa_testowa');

        $this->actingAs($user)->post(route('settings.data.export'))->assertRedirect();
        $export = DataExport::sole();
        $job = DB::table('jobs')->sole();
        $this->assertSame('low', $job->queue);
        $this->assertStringContainsString((string) $export->getKey(), $job->payload);
    }

    public function test_worker_ponawia_te_sama_prosbe_i_blokuje_druga_podczas_backoffu(): void
    {
        $user = $this->user('eksportponowienie');
        $this->actingAs($user)->post(route('settings.data.export'))->assertRedirect();
        config(['kuking.exports.disk' => 'brak-dysku']);
        $this->work();
        $export = DataExport::sole();
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, $job->attempts);
        $this->assertNull($job->reserved_at);
        $this->assertGreaterThan(now()->timestamp, $job->available_at);
        $this->assertSame(DataExport::STATUS_PROCESSING, $export->status);
        $this->assertNull($export->failure_reason);
        $this->actingAs($user)->post(route('settings.data.export'))->assertSessionHas('status',
            'Przygotowanie paczki z Twoimi danymi już trwa. Gotowość sprawdzisz w sekcji „Twoje paczki”.');
        $this->assertSame(1, DataExport::count());
        $this->assertSame(1, DB::table('jobs')->count());

        config(['kuking.exports.disk' => 'local']);
        $this->travel(121)->seconds();
        $this->work();
        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
        Storage::disk('local')->assertExists($export->object_key);
        $this->assertSame(1, DataExport::count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'low')->count());
    }

    public function test_przerwanie_po_zapisie_joba_cofa_takze_prosbe(): void
    {
        $user = $this->user();
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_starts_with($query->sql, 'insert into "jobs"')) {
                $injected = true;
                throw new \RuntimeException('Przerwanie po INSERT jobs, przed commitem.');
            }
        });
        $this->actingAs($user)->post(route('settings.data.export'))->assertServerError();
        $this->assertTrue($injected);
        $this->assertSame(0, DataExport::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_after_commit_w_konfiguracji_nie_rozdziela_zapisu(): void
    {
        config(['queue.connections.database.after_commit' => true]);
        $user = $this->user();
        DB::statement('ALTER TABLE jobs ADD CONSTRAINT odmowa_testowa CHECK (false) NOT VALID');
        $this->actingAs($user)->post(route('settings.data.export'))->assertServerError();
        $this->assertSame(0, DataExport::count());
        DB::statement('ALTER TABLE jobs DROP CONSTRAINT odmowa_testowa');
        $this->actingAs($user)->post(route('settings.data.export'))->assertRedirect();
        $this->assertSame(1, DataExport::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_odmowa_zapisu_powiadomienia_cofa_ready_i_pozwala_ponowic(): void
    {
        $this->actingAs($this->user())->post(route('settings.data.export'))->assertRedirect();
        DB::statement("ALTER TABLE jobs ADD CONSTRAINT odmowa_listu CHECK (payload NOT LIKE '%NotifyUserExportReady%') NOT VALID");
        $this->work();
        $export = DataExport::sole();
        $this->assertSame('processing', $export->status);
        $this->assertNull($export->completed_at);
        $this->assertNull($export->object_key);
        $this->assertSame(1, DB::table('jobs')->sole()->attempts);
        DB::statement('ALTER TABLE jobs DROP CONSTRAINT odmowa_listu');
        $this->travel(121)->seconds();
        $this->work();
        $this->assertSame('ready', $export->refresh()->status);
        $this->assertSame('default', DB::table('jobs')->sole()->queue);
    }

    public function test_worker_konczy_trzy_proby_listu_bez_cofania_gotowej_paczki(): void
    {
        $this->actingAs($this->user())->post(route('settings.data.export'))->assertRedirect();
        $this->work();
        Mail::shouldReceive('to')->times(3)->andThrow(new \RuntimeException('adres@example.com token=sekret'));
        $log = Log::spy();
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->travel(121)->seconds();
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->travel(301)->seconds();
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('ready', DataExport::sole()->status);
        $this->assertNull(DataExport::sole()->notified_at);
        $log->shouldHaveReceived('error')->with('Wyczerpano próby powiadomienia o paczce z danymi', \Mockery::type('array'))->once();
    }

    public function test_inne_polaczenie_kolejki_jest_odrzucone_przed_przyjeciem_prosby(): void
    {
        config(['database.connections.eksport_inne' => config('database.connections.pgsql'),
            'queue.connections.database.connection' => 'eksport_inne']);
        $this->actingAs($this->user())->post(route('settings.data.export'))->assertServerError();
        $this->assertSame(0, DataExport::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_worker_po_trzech_porazkach_zwalnia_miejsce_na_nowa_prosbe(): void
    {
        $user = $this->user('eksportkoniec');
        $this->actingAs($user)->post(route('settings.data.export'));
        config(['kuking.exports.disk' => 'brak-dysku']);
        $this->work();
        $this->travel(121)->seconds();
        $this->work();
        $this->travel(301)->seconds();
        $this->work();
        $export = DataExport::sole();
        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(DataExport::REASON_STORAGE, $export->failure_reason);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->actingAs($user)->post(route('settings.data.export'))->assertRedirect();
        $this->assertSame(2, DataExport::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'low', new WorkerOptions(sleep: 0));
    }

    public function test_list_ma_wlasne_ponowienie_bez_drugiego_budowania_zip(): void
    {
        $user = $this->user('eksportlist');
        $writes = 0;
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('writeStream')->andReturnUsing(function (...$args) use ($disk, &$writes) {
            $writes++;

            return $disk->writeStream(...$args);
        });
        Storage::set('local', $proxy);
        $sends = 0;
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andReturnUsing(function () use (&$sends): void {
            $sends++;
            if ($sends === 1) {
                throw new \RuntimeException('550 <prywatny@example.com> token=sekret');
            }
        });
        Mail::shouldReceive('to')->andReturn($pending);

        $this->actingAs($user)->post(route('settings.data.export'))->assertRedirect();
        $this->work();
        $export = DataExport::sole();
        $this->assertSame('ready', $export->status);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count(), 'Brak osobnego zadania powiadomienia.');
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->assertSame('ready', $export->refresh()->status);
        $this->assertNull($export->notified_at);
        $this->assertSame(1, DB::table('jobs')->count(), 'Brak zadania ponowienia listu.');
        $retry = DB::table('jobs')->sole();
        $this->assertSame(1, $retry->attempts);
        $this->assertGreaterThan(now()->timestamp, $retry->available_at);
        $url = URL::temporarySignedRoute('settings.data.download', $export->expires_at, ['export' => $export->id]);
        $this->actingAs($user)->get($url)->assertOk();

        $this->travel(121)->seconds();
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->assertNotNull($export->refresh()->notified_at);
        $this->assertSame(0, DB::table('jobs')->count());
        // Powtórne doręczenie zadania nie generuje drugiego listu.
        (new NotifyUserExportReady($export->id))->handle();
        $this->assertSame(2, $sends);
        $this->assertSame(1, $writes);
    }
}
