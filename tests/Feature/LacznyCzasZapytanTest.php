<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Monitoring\CzasZapytan;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Łączny czas zapytań SQL żądania nad progiem = jeden sygnał (#599).
 *
 * Pomiar stoi na PRAWDZIWYM PostgreSQL: `pg_sleep` daje czas, którego nie
 * da się podrobić atrapą. Dowód wymogu „N+1 z szybkich zapytań": trzy
 * zapytania po 40 ms, każde pod progiem 100 ms, razem nad nim.
 *
 * W konsoli (a więc w testach) `MonitoringServiceProvider` pomiaru nie
 * rejestruje — test rejestruje go sam, z własnym progiem.
 */
final class LacznyCzasZapytanTest extends TestCase
{
    private const ADRES = 'https://przyklad.test/webhook-bledow';

    /** @var list<MessageLogged> */
    private array $wpisy = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('logging.channels.blad_webhook.url', self::ADRES);
        config()->set('kuking.monitoring.seria_okno_minut', 15);
        Http::fake([self::ADRES => Http::response('ok', 200)]);
        Event::listen(MessageLogged::class, function (MessageLogged $wpis): void {
            $this->wpisy[] = $wpis;
        });

        CzasZapytan::zarejestruj($this->app, 100);

        Route::get('/_test/wolna/{przepis}', function () {
            for ($i = 0; $i < 3; $i++) {
                DB::select('select pg_sleep(0.04), ? as tajne', ['ktos@example.com']);
            }

            return 'ok';
        })->middleware('web');

        Route::get('/_test/szybka', function () {
            DB::select('select 1');

            return 'ok';
        })->middleware('web');
    }

    /** @return list<MessageLogged> */
    private function ostrzezenia(): array
    {
        return array_values(array_filter(
            $this->wpisy,
            fn (MessageLogged $w): bool => $w->level === 'warning' && str_contains($w->message, 'Łączny czas zapytań SQL'),
        ));
    }

    /** @return list<string> */
    private function wyslane(): array
    {
        return Http::recorded()
            ->filter(fn (array $para): bool => $para[0]->url() === self::ADRES)
            ->map(fn (array $para): string => (string) ($para[0]['text'] ?? ''))
            ->values()
            ->all();
    }

    public function test_kilka_szybkich_zapytan_ponad_progiem_daje_dokladnie_jeden_sygnal(): void
    {
        $this->get('/_test/wolna/zupa-babci?email=ktos@example.com')->assertOk();

        $ostrzezenia = $this->ostrzezenia();
        $this->assertCount(1, $ostrzezenia);
        $kontekst = $ostrzezenia[0]->context;
        $this->assertSame('GET /_test/wolna/{przepis}', $kontekst['trasa']);
        $this->assertSame(100, $kontekst['prog_ms']);
        // Pełny czas żądania, nie wartość tuż nad progiem.
        $this->assertGreaterThanOrEqual(120, $kontekst['czas_bazy_ms']);

        $wyslane = $this->wyslane();
        $this->assertCount(1, $wyslane);
        $this->assertStringContainsString('wolna baza: GET /_test/wolna/{przepis}', $wyslane[0]);

        // Kontrola ujemna treści: ani SQL, ani bindings, ani prawdziwy adres.
        foreach ([json_encode($kontekst), ...$wyslane] as $tresc) {
            $this->assertStringNotContainsString('pg_sleep', (string) $tresc);
            $this->assertStringNotContainsString('ktos@example.com', (string) $tresc);
            $this->assertStringNotContainsString('zupa-babci', (string) $tresc);
            $this->assertStringNotContainsString('email=', (string) $tresc);
        }
    }

    public function test_pod_progiem_jest_cisza(): void
    {
        $this->get('/_test/szybka')->assertOk();

        $this->assertSame([], $this->ostrzezenia());
        $this->assertSame([], $this->wyslane());
    }

    public function test_kazde_zadanie_mierzone_osobno(): void
    {
        // Dwa szybkie żądania po sobie nie sumują się w jedno „wolne".
        DB::select('select pg_sleep(0.15)');
        $this->get('/_test/szybka')->assertOk();
        $this->get('/_test/szybka')->assertOk();

        $this->assertSame([], $this->ostrzezenia());
    }

    public function test_seria_wolnych_zadan_tej_samej_trasy_to_jedna_wiadomosc_ale_kazde_w_dzienniku(): void
    {
        $this->get('/_test/wolna/a')->assertOk();
        $this->get('/_test/wolna/b')->assertOk();

        $this->assertCount(2, $this->ostrzezenia());
        $this->assertCount(1, $this->wyslane());
    }

    public function test_prog_zero_wylacza_pomiar(): void
    {
        $this->app->forgetInstance(CzasZapytan::class);
        $this->refreshApplication();
        Event::listen(MessageLogged::class, function (MessageLogged $wpis): void {
            $this->wpisy[] = $wpis;
        });
        CzasZapytan::zarejestruj($this->app, 0);
        Route::get('/_test/wolna2', function () {
            DB::select('select pg_sleep(0.05)');

            return 'ok';
        })->middleware('web');

        $this->get('/_test/wolna2')->assertOk();

        $this->assertSame([], $this->ostrzezenia());
    }
}
