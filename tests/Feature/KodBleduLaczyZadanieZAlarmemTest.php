<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CorrelateRequest;
use App\Http\Middleware\NormalizeForwardedFor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/** Pomiar HTTP i granicy sprzątania kontekstu; nie dowód propagacji do workera. */
final class KodBleduLaczyZadanieZAlarmemTest extends TestCase
{
    use WycinaObudoweEkranu;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('logs/korelacja-test-'.Str::uuid().'.log');
        config([
            'app.debug' => false,
            'logging.default' => 'correlation_test',
            'logging.channels.correlation_test' => ['driver' => 'single', 'path' => $this->logPath],
            'logging.channels.correlation_late' => ['driver' => 'single', 'path' => $this->logPath],
            'logging.channels.blad_webhook.url' => 'https://alarm.example.test/blad',
        ]);
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('correlation_test');
        Log::forgetChannel('correlation_late');
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
        parent::tearDown();
    }

    public function test_dwie_awarie_maja_ten_sam_odcisk_ale_osobne_kody_w_czterech_miejscach(): void
    {
        // Otwarcie kanału przed middleware oraz kanału wewnątrz trasy mierzy
        // obie gałęzie shareContext, nie tylko kanał domyślny.
        Log::channel('correlation_test');
        Route::get('/_test/korelacja', function (): never {
            Log::channel('correlation_late')->warning('Kanał otwarty podczas żądania');
            throw new RuntimeException('Kontrolowana awaria');
        });

        $ids = [];
        $fingerprints = [];
        foreach ([1, 2] as $attempt) {
            $response = $this->get('/_test/korelacja', ['X-Request-ID' => '11111111-1111-4111-8111-111111111111']);
            $response->assertStatus(500);
            $id = $response->headers->get('X-Request-ID');
            $this->assertTrue(Str::isUuid($id, 4));
            $this->assertNotSame('11111111-1111-4111-8111-111111111111', $id);
            $this->assertStringContainsString('Kod błędu: '.$id, $this->trescEkranu($response->getContent()));
            $this->assertStringContainsString('Podaj go, gdy do nas napiszesz.', $this->trescEkranu($response->getContent()));
            $log = file_get_contents($this->logPath);
            $this->assertMatchesRegularExpression('/Kanał otwarty podczas żądania[^\n]+'.preg_quote($id, '/').'/', $log);
            $this->assertMatchesRegularExpression('/Kontrolowana awaria[^\n]+'.preg_quote($id, '/').'/', $log);
            $ids[] = $id;
        }

        Http::assertSentCount(2);
        $alarms = Http::recorded()->map(fn ($pair) => $pair[0]['text'])->all();
        foreach ($ids as $index => $id) {
            $this->assertStringContainsString('żądanie: '.$id, $alarms[$index], 'BRAK_KORELACJI_ALARMU');
            $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $alarms[$index]);
            $this->assertStringNotContainsString('Kontrolowana awaria', $alarms[$index]);
            $this->assertSame(1, preg_match('/odcisk: ([a-f0-9]{8})/', $alarms[$index], $fingerprint));
            $fingerprints[] = $fingerprint[1];
        }
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame($fingerprints[0], $fingerprints[1]);
    }

    public function test_zwykla_odpowiedz_i_piecsetka_bez_wyjatku_maja_naglowek(): void
    {
        Route::get('/_test/korelacja-ok', fn () => response('gotowe'));
        Route::get('/_test/korelacja-status', fn () => response('awaria', 500));
        $first = $this->get('/_test/korelacja-ok')->assertOk();
        $second = $this->get('/_test/korelacja-status')->assertStatus(500);
        $this->assertTrue(Str::isUuid($first->headers->get('X-Request-ID'), 4));
        $this->assertTrue(Str::isUuid($second->headers->get('X-Request-ID'), 4));
        $this->assertNotSame($first->headers->get('X-Request-ID'), $second->headers->get('X-Request-ID'));
        Http::assertNothingSent();
    }

    public function test_po_zadaniu_kontekst_nie_przechodzi_do_logow_poza_http(): void
    {
        Log::shareContext(['test_marker' => 'zostaje']);
        Route::get('/_test/korelacja-cleanup', function () {
            Log::warning('wewnatrz');

            return response('gotowe');
        });
        $response = $this->get('/_test/korelacja-cleanup')->assertOk();
        $this->assertArrayNotHasKey('request_id', Log::sharedContext(), 'WYCIEK_KONTEKSTU_HTTP');
        $this->assertSame('zostaje', Log::sharedContext()['test_marker']);
        Log::warning('poza_http_stary_kanal');
        Log::channel('correlation_late')->warning('poza_http_nowy_kanal');
        $lines = array_filter(explode("\n", file_get_contents($this->logPath)), fn ($line) => str_contains($line, 'poza_http_'));
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString($response->headers->get('X-Request-ID'), $line);
            $this->assertStringNotContainsString('request_id', $line);
            $this->assertStringContainsString('zostaje', $line);
        }
    }

    public function test_finally_sprzata_rowniez_gdy_wyjatek_ucieka_poza_pipeline(): void
    {
        try {
            app(CorrelateRequest::class)->handle(Request::create('/'), fn () => throw new RuntimeException('poza pipeline'));
            $this->fail('Oczekiwano wyjątku');
        } catch (RuntimeException $e) {
            $this->assertSame('poza pipeline', $e->getMessage());
        }
        $this->assertArrayNotHasKey('request_id', Log::sharedContext());
    }

    public function test_json_500_zachowuje_naglowek_bez_dopisania_html(): void
    {
        Route::get('/_test/korelacja-json', fn () => throw new RuntimeException('json'));
        $response = $this->getJson('/_test/korelacja-json')->assertStatus(500);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID'), 4));
        $response->assertJsonStructure(['message']);
        $response->assertDontSee('Kod błędu:');
    }

    public function test_webhook_nie_wypisuje_dowolnej_tresci_z_pola_request_id(): void
    {
        Log::channel('blad_webhook')->error('Kontrolowany alarm', [
            'request_id' => "sekret@example.test\nwtargniecie",
            'exception' => new RuntimeException('Kontrolowana awaria'),
        ]);
        Http::assertSentCount(1);
        $alarm = Http::recorded()[0][0]['text'];
        $this->assertStringContainsString('RuntimeException', $alarm);
        $this->assertStringNotContainsString('sekret@example.test', $alarm);
        $this->assertStringNotContainsString('wtargniecie', $alarm);
        $this->assertStringNotContainsString('żądanie:', $alarm);
    }

    public function test_awaria_przed_middleware_ma_jawna_granice_bez_wymyslonego_kodu(): void
    {
        // Awaria pierwszej warstwy: korelacja jeszcze nie ruszyła.
        $this->mock(NormalizeForwardedFor::class)->shouldReceive('handle')->once()
            ->andThrow(new RuntimeException('przed korelacja'));
        $response = $this->get('/_test/przed-korelacja')->assertStatus(500);
        $response->assertHeaderMissing('X-Request-ID');
        $response->assertDontSee('Kod błędu:');
        $this->assertStringContainsString('Coś się u nas zepsuło', $this->trescEkranu($response->getContent()));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('żądanie:', Http::recorded()[0][0]['text']);
    }

    public function test_renderowanie_poza_middleware_nie_wymaga_bazy_ani_manifestu(): void
    {
        // Wywołujemy handler już po zakończeniu middleware, z tym samym
        // żądaniem. Atrybut zostaje, choć współdzielony kontekst logów znika.
        $request = Request::create('/_test/render');
        app(CorrelateRequest::class)->handle($request, fn () => response('gotowe'));
        $this->app->instance('request', $request);
        DB::shouldReceive('connection')->never();
        $this->withVite();
        $response = app(ExceptionHandler::class)->render($request, new RuntimeException('awaria'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Kod błędu: '.$request->attributes->get(CorrelateRequest::ATTRIBUTE), $this->trescEkranu($response->getContent()));
        Http::assertNothingSent();
    }
}
