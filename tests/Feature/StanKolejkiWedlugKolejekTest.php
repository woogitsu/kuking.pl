<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pomiar i alarm kolejki rozróżniają kolejki (issue #1030).
 *
 * Suma z całej tabeli `jobs` nie odróżnia zdrowej zaległości `default` od
 * `media`, której nikt nie bierze. Każda kolejka ma dziś własny proces
 * workera (`docker/entrypoint.sh`), więc alarm ma wskazać, który stoi.
 *
 * Kontrola dodatnia (zmierzona 24.09.2026): usunięcie `groupBy('queue')`
 * w `StanKolejki::wedlugKolejek()` wywraca oba testy tego pliku.
 */
class StanKolejkiWedlugKolejekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.kolejka.prog_zaleglosci_sekundy', 600);
        Carbon::setTestNow('2026-09-17 23:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function polozZadanie(string $kolejka, int $gotoweOd, ?int $zarezerwowaneOd = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $kolejka,
            // Payload celowo zawiera dane, których pomiar NIE MA prawa wynieść.
            'payload' => '{"displayName":"App\\\\Jobs\\\\TajneZadanie","data":{"adres":"ktos@przyklad.test"}}',
            'attempts' => 0,
            'reserved_at' => $zarezerwowaneOd === null ? null : Carbon::now()->getTimestamp() - $zarezerwowaneOd,
            'available_at' => Carbon::now()->getTimestamp() - $gotoweOd,
            'created_at' => Carbon::now()->getTimestamp() - max($gotoweOd, 0),
        ]);
    }

    public function test_liczba_i_wiek_gotowych_zadan_osobno_dla_kazdej_kolejki(): void
    {
        $this->polozZadanie('default', 5);
        $this->polozZadanie('default', 20);
        $this->polozZadanie('media', 900);
        $this->polozZadanie('low', 60);
        $this->polozZadanie('low', 3000, zarezerwowaneOd: 10); // w pracy — nie jest „gotowe”
        $this->polozZadanie('low', -300); // odłożone na później — też nie

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame([
            'media' => ['oczekujace' => 1, 'zaleglosc_sekundy' => 900],
            'low' => ['oczekujace' => 1, 'zaleglosc_sekundy' => 60],
            'default' => ['oczekujace' => 2, 'zaleglosc_sekundy' => 20],
        ], $wynik['kolejki']);
        $this->assertSame('media', $wynik['najstarsza_kolejka']);
        $this->assertSame(4, $wynik['oczekujace'], 'Kontrola: suma nadal liczy wszystkie gotowe zadania.');
    }

    public function test_alarm_wskazuje_glodujaca_kolejke_bez_payloadu(): void
    {
        $this->polozZadanie('default', 5);
        $this->polozZadanie('media', 900);
        DB::table('jobs')->insert([
            'queue' => 'ktos@przyklad.test', // nazwa spoza wzorca nie wychodzi na zewnątrz
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => Carbon::now()->getTimestamp() - 10,
            'created_at' => Carbon::now()->getTimestamp() - 10,
        ]);

        $wynik = app(StanKolejki::class)->sprawdz();
        $tresc = app(AlarmKolejki::class)->tresc($wynik);

        $this->assertSame(StanKolejki::ZALEGLOSC, $wynik['stan']);
        $this->assertStringContainsString('w kolejce `media`', $tresc);
        $this->assertStringContainsString('media 1 (900 s)', $tresc);
        $this->assertStringContainsString('default 1 (5 s)', $tresc);
        $this->assertStringContainsString('inna 1 (10 s)', $tresc);
        $this->assertStringNotContainsString('TajneZadanie', $tresc);
        $this->assertStringNotContainsString('ktos@przyklad.test', $tresc);
    }
}
