<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\StanKolejki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Kolejki osobno: `media` nie może się chować w sumie z `high` (issue #599,
 * „osobna widoczność media vs lżejsze kolejki").
 */
class KolejkiOsobnoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function zadanie(string $kolejka, int $czekaSekund): void
    {
        $kiedy = Carbon::now()->getTimestamp() - $czekaSekund;

        DB::table('jobs')->insert([
            'queue' => $kolejka,
            'payload' => '{"displayName":"App\\\\Jobs\\\\TajneZadanie"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $kiedy,
            'created_at' => $kiedy,
        ]);
    }

    private function nieudane(string $kolejka, Carbon $kiedy): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $kolejka,
            'payload' => '{}',
            'exception' => 'tajny-host.internal odmowa',
            'failed_at' => $kiedy,
        ]);
    }

    #[Test]
    public function zaleglosc_i_nieudane_sa_przypisane_do_wlasnej_kolejki(): void
    {
        $this->zadanie('media', 900);
        $this->zadanie('media', 30);
        $this->zadanie('high', 5);
        $this->zadanie('nieznana-kolejka', 40);
        $this->nieudane('media', Carbon::now()->subMinutes(10));
        // Poza oknem 3 h — nie liczy się jako zdarzenie.
        $this->nieudane('high', Carbon::now()->subDays(2));

        $kolejki = app(StanKolejki::class)->poKolejkach();

        $this->assertSame(['oczekujace' => 2, 'zaleglosc_sekundy' => 900, 'zawieszone' => 0, 'nieudane_w_oknie' => 1], $kolejki['media']);
        $this->assertSame(['oczekujace' => 1, 'zaleglosc_sekundy' => 5, 'zawieszone' => 0, 'nieudane_w_oknie' => 0], $kolejki['high']);
        $this->assertSame(0, $kolejki['default']['oczekujace']);
        $this->assertSame(0, $kolejki['low']['oczekujace']);
        // Nazwa spoza słownika nie wchodzi do dziennika wprost.
        $this->assertArrayNotHasKey('nieznana-kolejka', $kolejki);
        $this->assertSame(1, $kolejki['inne']['oczekujace']);

        // Kontrola spójności z sumą, na której stoi alarm.
        $suma = app(StanKolejki::class)->sprawdz();
        $this->assertSame($suma['oczekujace'], array_sum(array_column($kolejki, 'oczekujace')));
        $this->assertSame($suma['zaleglosc_sekundy'], max(array_column($kolejki, 'zaleglosc_sekundy')));
    }

    #[Test]
    public function linia_pomiaru_niesie_rozbicie_na_kolejki_bez_payloadu(): void
    {
        $this->zadanie('media', 120);

        $kanal = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('pomiary')->andReturn($kanal);

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(0);

        $kanal->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('kuking:sprawdz-kolejke', $wiadomosc);
                $this->assertSame(120, $kontekst['kolejki']['media']['zaleglosc_sekundy']);
                $this->assertSame(0, $kontekst['kolejki']['high']['oczekujace']);
                $this->assertFalse(str_contains((string) json_encode($kontekst), 'TajneZadanie'), 'payload w dzienniku');

                return true;
            })
            ->once();
    }
}
