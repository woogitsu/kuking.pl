<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Regresja #1331: nieudane sprzątanie wygasłych paczek z danymi nie dawało
 * żadnego aktywnego sygnału. `kuking:sprzataj-eksporty` kończyło się
 * sukcesem nawet bez jednej usuniętej paczki, a wpis w dzienniku serwera nie
 * jest alarmem. Teraz komenda zwraca błąd przy częściowej porażce, a czujka
 * `kuking:sprawdz-sprzatanie-eksportow` dzwoni na `blad_webhook`, gdy paczka
 * leży po terminie dłużej niż próg — także wtedy, gdy sprzątanie nie chodzi.
 *
 * Kontrola ujemna (wykonana przy tej zmianie): z wyłączonym
 * `zadzwonJesliTrzeba()` w czujce test na nieskutecznym storage oblewa;
 * po przywróceniu storage ten sam test sprawdza, że alarm cichnie.
 */
class SprzatanieEksportowDajeAlarmTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://przyklad.invalid/webhook-eksporty';

    private const KLUCZ = 'eksporty/basia-tajne-archiwum.zip';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(6, 25));
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    public function test_nieskuteczny_storage_daje_alarm_a_naprawiony_go_zamyka(): void
    {
        Storage::fake('local');
        $prawdziwy = Storage::disk('local');
        $prawdziwy->put(self::KLUCZ, 'kopia całego konta');

        $zepsuty = true;
        $dysk = Mockery::mock(Filesystem::class);
        // Zwykłe domknięcie z referencją: `fn` złapałoby `$zepsuty` przez wartość
        // i storage nigdy by się „nie naprawił".
        $dysk->shouldReceive('delete')->andReturnUsing(
            function (string $klucz) use (&$zepsuty, $prawdziwy): bool {
                return $zepsuty ? false : $prawdziwy->delete($klucz);
            },
        );
        $dysk->shouldReceive('exists')->andReturnUsing(fn (string $klucz): bool => $prawdziwy->exists($klucz));
        Storage::shouldReceive('disk')->with('local')->andReturn($dysk);

        $wlasciciel = $this->user('basia');
        $export = $this->paczka($wlasciciel->getKey(), now()->subHours(2));

        // Pierwsza porażka: rozpoznawalny błąd, adres zostaje, status `expired`.
        $this->artisan('kuking:sprzataj-eksporty')->assertFailed();
        $export->refresh();
        $this->assertSame(DataExport::STATUS_EXPIRED, $export->status);
        $this->assertSame('local', $export->disk);
        $this->assertSame(self::KLUCZ, $export->object_key);

        // Świeża porażka to jeszcze nie zaległość — noc później jest ponowienie.
        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertSuccessful();
        Http::assertNothingSent();

        // Dwie noce później storage nadal nie kasuje.
        $this->travel(2)->days();
        $this->artisan('kuking:sprzataj-eksporty')->assertFailed();
        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertFailed();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zadanie) use ($export, $wlasciciel): bool {
            $tresc = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('paczki z danymi', $tresc);
            $this->assertStringContainsString('liczba: 1', $tresc);
            $this->assertStringContainsString('kuking:sprzataj-eksporty', $tresc);
            // Bez PII i bez adresu obiektu.
            $this->assertStringNotContainsString(self::KLUCZ, $tresc);
            $this->assertStringNotContainsString('tajne-archiwum', $tresc);
            $this->assertStringNotContainsString((string) $export->getKey(), $tresc);
            $this->assertStringNotContainsString((string) $wlasciciel->email, $tresc);
            $this->assertStringNotContainsString('basia', $tresc);

            return true;
        });

        // Storage naprawiony: sprzątanie się udaje, czujka odwołuje raz i milknie.
        $zepsuty = false;
        $this->travel(1)->day();
        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();
        $this->assertNull($export->refresh()->object_key);
        $prawdziwy->assertMissing(self::KLUCZ);

        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertStringContainsString(
            'zaległość zniknęła',
            (string) (Http::recorded()[1][0]->data()['text'] ?? ''),
        );

        $this->travel(1)->day();
        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_sprzatanie_ktore_nie_chodzi_tez_daje_alarm(): void
    {
        // Paczka dalej `ready`, trzy dni po terminie: nikt jej nawet nie próbował skasować.
        $this->paczka($this->user('basia')->getKey(), now()->subDays(3), DataExport::STATUS_READY);

        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')
            ->expectsOutputToContain('Wygasłe paczki z danymi nadal w storage: 1')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_paczka_bez_adresu_i_swiezo_wygasla_nie_alarmuja(): void
    {
        $uzytkownik = $this->user('basia')->getKey();
        // Kontrola dodatnia progu: zdrowy stan nie może dzwonić.
        $this->paczka($uzytkownik, now()->subHours(20), DataExport::STATUS_READY);
        DataExport::create([
            'user_id' => $uzytkownik,
            'status' => DataExport::STATUS_EXPIRED,
            'disk' => null,
            'object_key' => null,
            'bytes' => null,
            'expires_at' => now()->subDays(10),
        ]);

        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_bez_kanalu_czujka_i_tak_konczy_sie_bledem(): void
    {
        config()->set('logging.channels.blad_webhook.url', null);
        Log::forgetChannel('blad_webhook');
        $this->paczka($this->user('basia')->getKey(), now()->subDays(3));

        $this->artisan('kuking:sprawdz-sprzatanie-eksportow')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_czujka_jest_w_harmonogramie_raz_na_dobe(): void
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->firstWhere('description', 'kuking:sprawdz-sprzatanie-eksportow');

        $this->assertNotNull($zadanie, 'Bez czujki nikt nie zauważy, że sprzątanie przestało chodzić.');
        $this->assertSame('25 6 * * *', $zadanie->expression);
    }

    private function paczka(string $uzytkownik, $wygasla, string $status = DataExport::STATUS_READY): DataExport
    {
        return DataExport::create([
            'user_id' => $uzytkownik,
            'status' => $status,
            'disk' => 'local',
            'object_key' => self::KLUCZ,
            'bytes' => 1234,
            // Komplet metadanych gotowej paczki — CHECK `data_exports_ready_complete_check` (#1365).
            'completed_at' => \Illuminate\Support\Carbon::parse($wygasla)->subDays(7),
            'expires_at' => $wygasla,
        ]);
    }
}
