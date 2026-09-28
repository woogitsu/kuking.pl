<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Monitoring\SeriaAlarmow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Seria identycznych błędów 500 to jedna wiadomość na okno (#599).
 *
 * Przed poprawką każde raportowane wystąpienie było osobnym żądaniem na
 * webhook: sto identycznych błędów = sto wiadomości (kontrola ujemna niżej
 * odtwarza to, wyłączając okno). Testy idą PRAWDZIWĄ ścieżką
 * `report()` → `bootstrap/app.php` → kanał `blad_webhook` → `Http::fake`.
 */
final class SeriaIdentycznychAlarmowTest extends TestCase
{
    private const ADRES = 'https://przyklad.test/webhook-bledow';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('logging.channels.blad_webhook.url', self::ADRES);
        config()->set('kuking.monitoring.seria_okno_minut', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ten sam plik i linia = ten sam odcisk. */
    private function awaria(): RuntimeException
    {
        return new RuntimeException('awaria testowa');
    }

    private function innaAwaria(): LogicException
    {
        return new LogicException('inna awaria');
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

    public function test_sto_identycznych_bledow_to_jedna_wiadomosc(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $wyjatek = $this->awaria();
        for ($i = 0; $i < 100; $i++) {
            report($wyjatek);
        }

        $this->assertCount(1, $this->wyslane());
        $this->assertStringNotContainsString('powtórzeń', $this->wyslane()[0]);
    }

    public function test_kontrola_ujemna_bez_okna_sto_bledow_to_sto_wiadomosci(): void
    {
        // Sabotaż: okno, które nigdy nie trzyma — tak zachowywał się kod
        // przed #599. Gdyby test wyżej przechodził i tutaj, niczego by nie mierzył.
        $this->app->instance(SeriaAlarmow::class, new class
        {
            public function zglos(string $odcisk, int $oknoMinut, \Closure $wyslij): ?bool
            {
                return $wyslij(0);
            }
        });
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $wyjatek = $this->awaria();
        for ($i = 0; $i < 100; $i++) {
            report($wyjatek);
        }

        $this->assertCount(100, $this->wyslane());
    }

    public function test_inny_odcisk_w_tym_samym_oknie_idzie_od_razu(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $pierwsza = $this->awaria();
        report($pierwsza);
        report($pierwsza);
        report($this->innaAwaria());

        $wyslane = $this->wyslane();
        $this->assertCount(2, $wyslane);
        $this->assertStringContainsString('RuntimeException', $wyslane[0]);
        $this->assertStringContainsString('LogicException', $wyslane[1]);
    }

    public function test_po_oknie_wiadomosc_niesie_liczbe_pominietych(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);
        Carbon::setTestNow('2026-09-25 10:00:00');

        $wyjatek = $this->awaria();
        for ($i = 0; $i < 8; $i++) {
            report($wyjatek);
        }
        $this->assertCount(1, $this->wyslane());

        Carbon::setTestNow('2026-09-25 10:16:00');
        report($wyjatek);

        $wyslane = $this->wyslane();
        $this->assertCount(2, $wyslane);
        $this->assertStringContainsString('powtórzeń od poprzedniej wiadomości (nie wysłanych osobno): 7', $wyslane[1]);
    }

    public function test_odpowiedz_429_nie_kupuje_okna_ciszy(): void
    {
        Http::fakeSequence(self::ADRES)
            ->push('za dużo', 429)
            ->push('ok', 200);
        Carbon::setTestNow('2026-09-25 10:00:00');

        $wyjatek = $this->awaria();
        report($wyjatek);
        // W krótkiej przerwie po porażce nie wołamy martwego kanału przy każdym żądaniu.
        report($wyjatek);
        $this->assertCount(1, $this->wyslane());

        // Minuta, nie kwadrans: porażka dała przerwę, nie okno ciszy.
        Carbon::setTestNow('2026-09-25 10:01:01');
        report($wyjatek);

        $wyslane = $this->wyslane();
        $this->assertCount(2, $wyslane);
        $this->assertStringContainsString('(nie wysłanych osobno): 1', $wyslane[1]);
    }

    public function test_timeout_webhooka_nie_kupuje_okna_ciszy(): void
    {
        $proby = 0;
        Http::fake([self::ADRES => function () use (&$proby) {
            $proby++;
            if ($proby === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response('ok', 200);
        }]);
        Carbon::setTestNow('2026-09-25 10:00:00');

        $wyjatek = $this->awaria();
        report($wyjatek);
        Carbon::setTestNow('2026-09-25 10:01:01');
        report($wyjatek);

        $this->assertSame(2, $proby);
    }

    public function test_klucz_serii_nie_niesie_komunikatu_wyjatku(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        // Ta sama linia, różne komunikaty (np. różne adresy e-mail z SQL-a).
        // Gdyby komunikat wchodził do odcisku, byłyby to dwie grupy.
        foreach (['ktos@example.com', 'ktos-inny@example.com'] as $komunikat) {
            report(new RuntimeException($komunikat));
        }

        $this->assertCount(1, $this->wyslane());
    }

    public function test_niedostepny_cache_nie_wycisza_alarmu(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache padł'));

        $wyjatek = $this->awaria();
        report($wyjatek);
        report($wyjatek);

        $this->assertCount(2, $this->wyslane());
    }

    public function test_strona_bledu_dalej_dziala_przy_serii(): void
    {
        config()->set('app.debug', false);
        Http::fake([self::ADRES => Http::response('ok', 200)]);
        Route::get('/_test/seria', fn () => throw new RuntimeException('seria'))->middleware('web');

        $this->get('/_test/seria')->assertStatus(500);
        $this->get('/_test/seria')->assertStatus(500);

        $this->assertCount(1, $this->wyslane());
    }
}
