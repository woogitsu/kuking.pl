<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Jobs\GenerateUserExport;
use App\Jobs\NotifyUserExportReady;
use App\Mail\DataExportReady;
use App\Mail\DataExportReadyInGracePeriod;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * List „paczka gotowa" po awarii poczty (issue #820).
 *
 * Do 23 września 2026 list wysyłał sam `GenerateUserExport`, raz: awaria
 * poczty była łapana i logowana, paczka zostawała gotowa, a człowiek nie
 * dostawał nic i NIKT tego nie ponawiał. Teraz list ma własne zadanie
 * (`NotifyUserExportReady`) z ponowieniami i zamkiem `notified_at`.
 *
 * Ten plik mierzy trzy obietnice:
 *  1. awaria poczty nie rusza paczki, a następna próba list wysyła,
 *  2. list idzie NAJWYŻEJ RAZ — także przy dwóch przebiegach naraz,
 *  3. zlecenie listu i `ready` zatwierdzają się razem (kolejka bazodanowa).
 *
 * KONTROLE UJEMNE (wykonane przy pisaniu):
 *  - bez zwolnienia zajęcia w `catch` → `test_awaria_poczty_…` oblewa
 *    (druga próba nie wysyła, `notified_at` zostaje),
 *  - `zajmij()` bez `whereNull('notified_at')` →
 *    `test_rownolegly_przebieg_…` oblewa (dwa listy),
 *  - `NotifyUserExportReady::dispatch()` przeniesione ZA transakcję
 *    `finalize()` → `test_gdy_zlecenie_listu_sie_nie_zapisze_…` oblewa
 *    (paczka `ready` bez zadania listu).
 */
class ListOGotowejPaczceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    public function test_awaria_poczty_nie_rusza_paczki_a_nastepna_proba_wysyla_list(): void
    {
        $basia = $this->user('basialist');
        $export = $this->gotowaPaczka($basia);

        $prawdziwaPoczta = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('550 5.1.1 <'.$basia->email.'>: Recipient address rejected'));

        try {
            (new NotifyUserExportReady((string) $export->getKey()))->handle();
            $this->fail('Awaria poczty musi wrócić do kolejki jako wyjątek — inaczej nie będzie ponowienia.');
        } catch (RuntimeException $e) {
            // Wyjątek trafia do `failed_jobs` — nie może nieść adresu z transportu.
            $this->assertStringNotContainsString($basia->email, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $export->refresh();
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertTrue($export->isDownloadable());
        $this->assertNull($export->notified_at, 'Nieudana próba nie może zostawić zajęcia — ponowienie by nic nie wysłało.');

        // Poczta wraca (MailFake buduje się z prawdziwego menedżera, nie z mocka).
        Mail::swap($prawdziwaPoczta);
        Mail::fake();

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertSent(DataExportReady::class, 1);
        Mail::assertSent(DataExportReady::class, fn (DataExportReady $list): bool => $list->hasTo($basia->email));
        $this->assertNotNull($export->refresh()->notified_at);
    }

    public function test_zadanie_listu_ma_ponowienia_na_kolejce_ktora_worker_odbiera(): void
    {
        $zadanie = new NotifyUserExportReady('x');

        $this->assertGreaterThan(1, $zadanie->tries, 'Bez kilku prób awaria poczty znów kończy się brakiem listu.');
        $this->assertCount($zadanie->tries - 1, $zadanie->backoff);
        $this->assertSame('default', $zadanie->queue);
    }

    public function test_list_idzie_najwyzej_raz(): void
    {
        Mail::fake();

        $basia = $this->user('basiaraz');
        $export = $this->gotowaPaczka($basia);

        (new NotifyUserExportReady((string) $export->getKey()))->handle();
        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertSent(DataExportReady::class, 1);
    }

    /**
     * Dwa przebiegi naraz: oba odczytały `notified_at = null`, zanim któryś
     * go ustawił. Drugi przebieg wchodzi tu przez zdarzenie `retrieved` —
     * dokładnie między odczytem w `handle()` a zajęciem listu.
     */
    public function test_rownolegly_przebieg_ktory_zajal_list_pierwszy_blokuje_drugi_list(): void
    {
        Mail::fake();

        $basia = $this->user('basiawyscig');
        $export = $this->gotowaPaczka($basia);

        DataExport::retrieved(function (DataExport $odczytany): void {
            DB::table('data_exports')
                ->where('id', $odczytany->getKey())
                ->whereNull('notified_at')
                ->update(['notified_at' => now()->subSecond()]);
        });

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertNothingSent();
    }

    public function test_konto_w_karencji_dostaje_list_z_cofnieciem_usuniecia(): void
    {
        Mail::fake();

        $basia = $this->user('basiakarencja');
        $basia->markForDeletion();
        $export = $this->gotowaPaczka($basia);

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertNotSent(DataExportReady::class);
        Mail::assertSent(DataExportReadyInGracePeriod::class, 1);
    }

    public function test_konto_wymazane_nie_dostaje_listu(): void
    {
        Mail::fake();

        $basia = $this->user('basiawymaz');
        $basia->markForDeletion();
        $export = $this->gotowaPaczka($basia);

        $this->assertTrue(app(EraseAccountData::class)->handle($basia->fresh()));

        // Izolacja strażnika konta: nawet gdyby paczka nadal wyglądała na
        // pobieralną, wymazane konto listu nie dostaje.
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_READY,
            'expires_at' => now()->addDay(),
        ]);

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertNothingSent();
        $this->assertNull($export->refresh()->notified_at);
    }

    public function test_paczka_po_terminie_nie_dostaje_listu(): void
    {
        Mail::fake();

        $export = $this->gotowaPaczka($this->user('basiastara'));
        DB::table('data_exports')->where('id', $export->getKey())->update(['expires_at' => now()->subMinute()]);

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        Mail::assertNothingSent();
    }

    public function test_na_kolejce_bazodanowej_budowa_paczki_zleca_list_zamiast_go_wysylac(): void
    {
        Mail::fake();
        Queue::fake();
        config(['queue.default' => 'database']);

        $export = $this->zamowionaPaczka($this->user('basiazlecenie'));

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
        Mail::assertNothingSent();
        Queue::assertPushedOn('default', NotifyUserExportReady::class,
            fn (NotifyUserExportReady $zadanie): bool => $zadanie->dataExportId === (string) $export->getKey());
        Queue::assertPushed(NotifyUserExportReady::class, 1);
    }

    /**
     * Zlecenie listu i `ready` w JEDNEJ transakcji: gdy wiersz w `jobs` się
     * nie zapisze, paczka nie może się ogłosić gotową bez listu, którego
     * potem nikt nie zleci. Awaria zapisu do kolejki odgrywana przez nazwę
     * tabeli, której nie ma — prawdziwy `INSERT`, prawdziwy błąd PostgreSQL.
     */
    public function test_gdy_zlecenie_listu_sie_nie_zapisze_paczka_nie_jest_gotowa(): void
    {
        Mail::fake();
        Log::spy();
        config([
            'queue.default' => 'database',
            'queue.connections.database.table' => 'nie_ma_takiej_tabeli_zadan',
        ]);

        $export = $this->zamowionaPaczka($this->user('basiaoutbox'));

        try {
            (new GenerateUserExport((string) $export->getKey()))->handle();
        } catch (\Throwable) {
            // Oczekiwane: kolejka ponowi całą budowę.
        }

        $export->refresh();
        $this->assertNotSame(DataExport::STATUS_READY, $export->status);
        $this->assertNull($export->completed_at);
        Mail::assertNothingSent();
    }

    public function test_kontrola_dodatnia_na_prawdziwej_kolejce_bazodanowej_zadanie_listu_lezy_w_jobs(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);

        $export = $this->zamowionaPaczka($this->user('basiajobs'));

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')
            ->where('payload', 'like', '%NotifyUserExportReady%')->count());
        Mail::assertNothingSent();
    }

    public function test_na_kolejce_sync_awaria_poczty_nie_zamienia_gotowej_paczki_w_nieudana(): void
    {
        $export = $this->zamowionaPaczka($this->user('basiasync'));

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Connection refused'));

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertNull($export->failure_reason);
        $this->assertNull($export->notified_at);
    }

    // -----------------------------------------------------------------
    // Ekran ustawień: paczka gotowa, list jeszcze nie
    // -----------------------------------------------------------------

    public function test_ekran_mowi_ze_na_list_nie_trzeba_czekac_dopoki_nie_wyszedl(): void
    {
        $basia = $this->user('basiaekran');
        $export = $this->gotowaPaczka($basia);

        $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertSee('Pobierz paczkę')
            ->assertSee('E-mail o tej paczce jeszcze nie wyszedł');

        DB::table('data_exports')->where('id', $export->getKey())->update(['notified_at' => now()]);

        $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertSee('Pobierz paczkę')
            ->assertDontSee('E-mail o tej paczce jeszcze nie wyszedł');
    }

    public function test_paczka_ready_po_terminie_nie_jest_nazywana_gotowa(): void
    {
        $basia = $this->user('basiaekranstary');
        $export = $this->gotowaPaczka($basia);
        DB::table('data_exports')->where('id', $export->getKey())->update(['expires_at' => now()->subMinute()]);

        $odpowiedz = $this->actingAs($basia)->get(route('settings.data'))->assertOk();

        $odpowiedz->assertDontSee('Pobierz paczkę');
        // Nie `assertDontSee('— gotowa')`: między myślnikiem a stanem jest
        // łamanie wiersza, więc tamta asercja nie mogła oblać (issue #819).
        $this->assertDoesNotMatchRegularExpression('/—\s+gotowa\b/u', (string) $odpowiedz->getContent());
        $odpowiedz->assertSee('Tej paczki nie można już pobrać.');
    }

    public function test_komunikat_po_zamowieniu_nie_obiecuje_listu_gdy_poczta_nie_wysyla(): void
    {
        Queue::fake();
        $basia = $this->user('basiabezpoczty');

        // Suita testów chodzi na `array` — dla `Poczta::dziala()` to „nie wysyła”.
        $this->actingAs($basia)->post(route('settings.data.export'))
            ->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, '„Twoje paczki”')
                && ! str_contains($tekst, 'e-mail'));
    }

    public function test_kontrola_dodatnia_komunikat_obiecuje_list_gdy_poczta_wysyla(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.scheme' => 'smtp']);
        $basia = $this->user('basiazpoczta');

        $this->actingAs($basia)->post(route('settings.data.export'))
            ->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, '„Twoje paczki”')
                && str_contains($tekst, 'Napiszemy też do Ciebie e-mail'));
    }

    // -----------------------------------------------------------------

    private function zamowionaPaczka(User $user): DataExport
    {
        return DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);
    }

    /**
     * Paczka `ready` zapisana wprost — bez `GenerateUserExport`, który na
     * kolejce `sync` wysłałby list od razu i zaciemnił liczenie listów.
     */
    private function gotowaPaczka(User $user): DataExport
    {
        $export = $this->zamowionaPaczka($user);

        $export->forceFill([
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/'.$user->getKey().'/'.$export->getKey().'-kuking-moje-dane.zip',
            'bytes' => 3,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ])->save();

        Storage::disk('local')->put((string) $export->object_key, 'zip');

        return $export->refresh();
    }
}
