<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Tests\TestCase;

/**
 * Eksport między próbami i eksport, którego kolejka nie przyjęła
 * (issues #823 i #824).
 *
 * #823: `GenerateUserExport` ma trzy próby z backoffem 2 i 5 minut, ale
 * `handle()` po KAŻDYM wyjątku od razu ustawiał `failed`. To stan spoza
 * indeksu `data_exports_one_active_per_user`, więc w czasie backoffu
 * człowiek widział „spróbuj jeszcze raz", zamawiał drugą paczkę, a
 * ponowienie pierwszej wracało na konto, które ma już inny aktywny eksport.
 * Teraz między próbami rekord stoi w `queued`, a `failed` przychodzi
 * dopiero po ostatniej.
 *
 * Mierzone na PRAWDZIWEJ kolejce bazodanowej i prawdziwym workerze
 * (`runNextJob`), nie na `sync` ani `Queue::fake()` — przy nich nie ma
 * backoffu, czyli nie ma stanu, o który chodzi.
 *
 * #824: rekord i zadanie zatwierdzają się razem od audytu A02
 * (`EksportNieUtykaMiedzyCommitemAWyslaniemTest`). Tu dochodzi ostatni
 * kawałek: awaria bazy przy zapisie zadania kończy się zdaniem po polsku,
 * co zrobić, a nie ekranem 500.
 */
class EksportCzekaNaPonowienieJakoAktywnyTest extends TestCase
{
    use RefreshDatabase;

    private const JUZ_TRWA = 'Przygotowanie paczki z Twoimi danymi już trwa. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');

        config([
            'queue.default' => 'database',
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    private function zepsujMagazyn(): void
    {
        // Dysk, którego nie ma w konfiguracji — ta sama awaria magazynu,
        // której używa `DataExportTest`.
        config(['kuking.exports.disk' => 'dysk-ktorego-nie-ma']);
    }

    private function naprawMagazyn(): void
    {
        config(['kuking.exports.disk' => 'local']);
    }

    /** Jeden obrót workera na kolejce eksportów — tak jak `queue:work --queue=low`. */
    private function workerBierzeZadanie(): void
    {
        app('queue.worker')->runNextJob('database', 'low', new WorkerOptions(sleep: 0));
    }

    private function zadaniaEksportu(): int
    {
        return DB::table('jobs')->where('queue', 'low')->count();
    }

    private function eksportyBasi(User $basia): int
    {
        return DataExport::query()->where('user_id', $basia->getKey())->count();
    }

    public function test_nieudana_pierwsza_proba_zostawia_prosbe_aktywna_i_nowa_nie_powstaje(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();
        $this->assertSame(1, $this->zadaniaEksportu(), 'Zadanie nie weszło do kolejki — test nie zmierzy ponowienia.');

        $this->zepsujMagazyn();
        $this->workerBierzeZadanie();

        $export = DataExport::query()->firstOrFail();

        // Zadanie wróciło do kolejki z opóźnieniem — próba naprawdę padła
        // i naprawdę czeka na ponowienie. Bez tego test niżej nie mierzy
        // stanu „w trakcie backoffu".
        $zadanie = DB::table('jobs')->where('queue', 'low')->first();
        $this->assertNotNull($zadanie, 'Kolejka nie zaplanowała ponowienia.');
        $this->assertSame(1, (int) $zadanie->attempts);
        $this->assertGreaterThan(now()->getTimestamp(), (int) $zadanie->available_at);

        // SEDNO #823: przed poprawką stało tu `failed`.
        $this->assertSame(DataExport::STATUS_QUEUED, $export->status);

        // Druga prośba w czasie backoffu nie zakłada drugiej paczki.
        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));
        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', fn (string $tekst): bool => str_starts_with($tekst, self::JUZ_TRWA));

        $this->assertSame(1, $this->eksportyBasi($basia));
        $this->assertSame(1, $this->zadaniaEksportu());
    }

    /** KONTROLA DODATNIA: ponowienie tej samej prośby daje jedną gotową paczkę. */
    public function test_udane_ponowienie_konczy_te_sama_prosbe_jedna_paczka(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->zepsujMagazyn();
        $this->workerBierzeZadanie();
        $this->naprawMagazyn();

        $this->travel(3)->minutes();
        $this->workerBierzeZadanie();

        $export = DataExport::query()->firstOrFail();

        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertNull($export->failure_reason);
        $this->assertSame(1, $this->eksportyBasi($basia));
        $this->assertSame(0, $this->zadaniaEksportu());
        Storage::disk('local')->assertExists((string) $export->object_key);
    }

    /** Po wyczerpaniu prób: `failed` z kodem przyczyny i droga do nowej prośby otwarta. */
    public function test_wyczerpanie_prob_daje_failed_i_odblokowuje_nowa_prosbe(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->zepsujMagazyn();

        $this->workerBierzeZadanie();
        $this->assertSame(DataExport::STATUS_QUEUED, DataExport::query()->firstOrFail()->status);

        $this->travel(3)->minutes();
        $this->workerBierzeZadanie();
        $this->assertSame(DataExport::STATUS_QUEUED, DataExport::query()->firstOrFail()->status);

        $this->travel(6)->minutes();
        $this->workerBierzeZadanie();

        $export = DataExport::query()->firstOrFail();

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(DataExport::REASON_STORAGE, $export->failure_reason);
        $this->assertSame(0, $this->zadaniaEksportu());

        $this->naprawMagazyn();

        $this->actingAs($basia)->post(route('settings.data.export'))
            ->assertSessionHas('status', fn (string $tekst): bool => str_starts_with($tekst, 'Przygotowujemy paczkę'));

        $this->assertSame(2, $this->eksportyBasi($basia));
    }

    /**
     * Eksport unieważniony przez wymazanie konta to porażka ostateczna już
     * przy pierwszej próbie — bez czekania na ponowienia.
     */
    public function test_wymazane_konto_konczy_sie_failed_bez_ponowienia(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        // Tak `EraseAccountData` unieważnia eksport `queued` (issue #1307).
        DataExport::query()->where('user_id', $basia->getKey())->update(['expires_at' => now()->subSecond()]);

        $this->workerBierzeZadanie();

        $export = DataExport::query()->firstOrFail();

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(DataExport::REASON_ACCOUNT_MISSING, $export->failure_reason);
        $this->assertSame(0, $this->zadaniaEksportu());
    }

    /**
     * #824: baza odrzuca zapis zadania. Nic nie zostaje, człowiek dostaje
     * zdanie po polsku z tym, co zrobić — i następna próba przechodzi.
     */
    public function test_awaria_zapisu_zadania_daje_komunikat_i_da_sie_ponowic(): void
    {
        $basia = $this->user('basia');

        $padlo = false;

        DB::beforeExecuting(function (string $zapytanie) use (&$padlo): void {
            if (! $padlo && str_contains($zapytanie, 'insert into "jobs"')) {
                $padlo = true;

                throw new QueryException('pgsql', $zapytanie, [], new PDOException('kolejka nie przyjmuje zadań (awaria wymuszona testem)'));
            }
        });

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));

        $this->assertTrue($padlo, 'Sabotaż nie trafił w zapis do kolejki — test nie zmierzył niczego.');

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, 'nic nie zostało zapisane')
            && str_contains($tekst, 'Spróbuj jeszcze raz'));

        $this->assertSame(0, $this->eksportyBasi($basia));
        $this->assertSame(0, DB::table('jobs')->count());

        $this->actingAs($basia)->post(route('settings.data.export'))
            ->assertSessionHas('status', fn (string $tekst): bool => str_starts_with($tekst, 'Przygotowujemy paczkę'));

        $this->assertSame(1, $this->eksportyBasi($basia));
        $this->assertSame(1, $this->zadaniaEksportu());

        $zadanie = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(GenerateUserExport::class, $zadanie['displayName']);
        $this->assertStringContainsString((string) DataExport::query()->value('id'), $zadanie['data']['command']);
    }
}
