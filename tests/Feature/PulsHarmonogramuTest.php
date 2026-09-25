<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `kuking:puls-harmonogramu` — znak życia harmonogramu dla zewnętrznego
 * monitora (issue #599). Stojący harmonogram ucisza WSZYSTKIE czujki naraz,
 * a milczenie czujki wygląda jak spokój; puls odwraca kierunek pytania.
 */
class PulsHarmonogramuTest extends TestCase
{
    private const ADRES = 'https://monitor.przyklad.test/ping/TAJNY-TOKEN-599';

    #[Test]
    public function bez_adresu_nic_nie_wysyla_i_konczy_sie_sukcesem(): void
    {
        config()->set('kuking.monitoring.puls_harmonogramu_url', null);
        Http::fake();

        $this->artisan('kuking:puls-harmonogramu')->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function z_adresem_wysyla_dokladnie_jeden_get_na_ten_adres(): void
    {
        config()->set('kuking.monitoring.puls_harmonogramu_url', self::ADRES);
        Http::fake([self::ADRES => Http::response('OK', 200)]);

        $this->artisan('kuking:puls-harmonogramu')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === self::ADRES);
    }

    #[Test]
    public function odmowa_monitora_to_porazka_i_adres_nie_trafia_do_dziennika(): void
    {
        config()->set('kuking.monitoring.puls_harmonogramu_url', self::ADRES);
        Http::fake([self::ADRES => Http::response('nie', 503)]);
        Log::spy();

        // Niezerowy kod: `Harmonogram` zamienia go w porażkę zadania, a ta
        // w wpis błędu — czyli w coś, co da się zauważyć.
        $this->artisan('kuking:puls-harmonogramu')
            ->doesntExpectOutputToContain('TAJNY-TOKEN')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->withArgs(function (string $wiadomosc, array $kontekst = []): bool {
            $this->assertSame(503, $kontekst['status'] ?? null);
            $this->assertFalse(str_contains($wiadomosc.json_encode($kontekst), 'TAJNY-TOKEN'), 'Token pulsu wyciekł do dziennika.');

            return true;
        })->once();
    }

    #[Test]
    public function brak_polaczenia_to_porazka_bez_tresci_wyjatku(): void
    {
        config()->set('kuking.monitoring.puls_harmonogramu_url', self::ADRES);
        Http::fake(fn () => throw new ConnectionException('cURL error 6 dla '.self::ADRES));
        Log::spy();

        $this->artisan('kuking:puls-harmonogramu')->assertExitCode(1);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc, array $kontekst = []): bool => ! str_contains($wiadomosc.json_encode($kontekst), 'TAJNY-TOKEN'),
        )->once();
    }

    #[Test]
    public function adres_bez_https_nie_jest_wolany(): void
    {
        config()->set('kuking.monitoring.puls_harmonogramu_url', 'http://monitor.przyklad.test/ping/abc');
        Http::fake();

        $this->artisan('kuking:puls-harmonogramu')->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function puls_jest_w_harmonogramie_co_piec_minut(): void
    {
        $zdarzenie = collect(app(Schedule::class)->events())->firstWhere('description', 'kuking:puls-harmonogramu');

        $this->assertNotNull($zdarzenie, 'Puls nie jest zarejestrowany w harmonogramie — monitor alarmowałby bez przerwy.');
        $this->assertSame('*/5 * * * *', $zdarzenie->expression);
    }
}
