<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Harmonogram nie ma prawa mierzyć w próżnię (issue #598, #599).
 *
 * SKĄD SIĘ WZIĄŁ TEN PLIK
 * Zmierzone na produkcji 17.09.2026 23:25:20 UTC: harmonogram uruchamia
 * `kuking:budzet-polaczen`, melduje „DONE" w 21 ms — i to wszystko, co po
 * tym zostaje. Zmierzonych liczb nie ma nigdzie, bo `Schedule::call()` woła
 * komendę przez `Artisan::call()`, a to przechwytuje wyjście konsoli do
 * bufora, który po zakończeniu przebiegu przestaje istnieć.
 *
 * Skutek jest konkretny: definicji gotowości #598 („znany peak active
 * connections przy obecnej topologii") nie dało się spełnić MIMO działającej
 * czujki, bo każdy przebieg mierzył i natychmiast zapominał. Do produkcyjnego
 * Postgresa nie ma dziś dostępu z zewnątrz — nie ma proxy TCP i nie ma
 * zalogowanego CLI — więc wpis w dzienniku serwera jest JEDYNĄ drogą, którą
 * szereg czasowy może w ogóle powstać.
 *
 * CZEGO TEN PLIK NIE DOWODZI: że szereg czasowy powstanie na produkcji.
 * Zależy to od `LOG_LEVEL` w panelu, którego ta sesja nie może odczytać.
 * Napisane wprost w `docs/DATABASE.md`.
 */
class PomiarCzujekTrafiaDoDziennikaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.blad_webhook.url', null);
        Carbon::setTestNow('2026-09-18 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    //  Budżet połączeń
    // -----------------------------------------------------------------

    #[Test]
    public function budzet_polaczen_zapisuje_pomiar_do_dziennika(): void
    {
        $dziennik = Log::spy();

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])->assertExitCode(0);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('kuking:budzet-polaczen', $wiadomosc);

                // Kontrola DODATNIA: w kontekście są liczby, z których da się
                // zbudować szereg czasowy, a nie sama ocena „spokojnie".
                $this->assertSame('spokojny', $kontekst['stan']);
                $this->assertIsInt($kontekst['zajete_serwer']);
                $this->assertIsInt($kontekst['dostepne']);
                $this->assertIsInt($kontekst['max_connections']);
                $this->assertGreaterThan(0, $kontekst['max_connections']);
                $this->assertGreaterThanOrEqual(1, $kontekst['zajete_baza']);

                return true;
            })
            ->once();
    }

    #[Test]
    public function wpis_w_dzienniku_nie_wynosi_nazwy_bazy_ani_hosta(): void
    {
        $dziennik = Log::spy();

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])->assertExitCode(0);

        $nazwaBazy = DB::getDatabaseName();

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($nazwaBazy): bool {
                // Kontrola UJEMNA: dziennik produkcyjny czyta także dostawca
                // hostingu. Ta sama zasada, co przy webhooku (audyt A6-01).
                $zserializowany = json_encode($kontekst, JSON_UNESCAPED_UNICODE);

                $this->assertStringNotContainsString($nazwaBazy, (string) $zserializowany);
                $this->assertStringNotContainsString('127.0.0.1', (string) $zserializowany);
                $this->assertStringNotContainsString('kuking', (string) $zserializowany);
                $this->assertArrayNotHasKey('baza', $kontekst);

                return true;
            })
            ->once();
    }

    #[Test]
    public function stan_bez_pomiaru_nie_udaje_wpisu_z_liczbami(): void
    {
        // Gdy serwer nie odpowiada, komenda kończy się wcześniej. Wpis
        // z zerami byłby gorszy niż jego brak: w szeregu czasowym wyglądałby
        // jak prawdziwy pomiar mówiący „zero połączeń".
        $polaczenie = Mockery::mock(ConnectionInterface::class);
        $polaczenie->shouldReceive('getDriverName')->andReturn('pgsql');
        $polaczenie->shouldReceive('select')->andThrow(new \RuntimeException('padło'));

        DB::shouldReceive('connection')->andReturn($polaczenie);

        $dziennik = Log::spy();

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])->assertExitCode(1);

        $dziennik->shouldNotHaveReceived('info');
    }

    // -----------------------------------------------------------------
    //  Kolejka
    // -----------------------------------------------------------------

    #[Test]
    public function czujka_kolejki_zapisuje_pomiar_do_dziennika(): void
    {
        $dziennik = Log::spy();

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(0);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('kuking:sprawdz-kolejke', $wiadomosc);
                $this->assertSame('spokojna', $kontekst['stan']);
                $this->assertSame(0, $kontekst['oczekujace']);
                $this->assertSame(0, $kontekst['zaleglosc_sekundy']);
                $this->assertArrayHasKey('nieudane_razem', $kontekst);

                return true;
            })
            ->once();
    }

    #[Test]
    public function wpis_kolejki_zapisuje_zalegosc_takze_gdy_jest_alarm(): void
    {
        // Szereg czasowy ma być ciągły. Gdyby wpis powstawał tylko przy
        // spokoju, zniknąłby dokładnie w tych godzinach, które są ciekawe.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\TajneZadanie","data":{"adres":"ktos@przyklad.test"}}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => Carbon::now()->getTimestamp() - 900,
            'created_at' => Carbon::now()->getTimestamp() - 900,
        ]);

        $dziennik = Log::spy();

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(1);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('zaleglosc', $kontekst['stan']);
                $this->assertSame(900, $kontekst['zaleglosc_sekundy']);
                $this->assertSame(1, $kontekst['oczekujace']);

                // Kontrola UJEMNA: `payload` nie ma prawa wejść do dziennika.
                $zserializowany = (string) json_encode($kontekst, JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('TajneZadanie', $zserializowany);
                $this->assertStringNotContainsString('ktos@przyklad.test', $zserializowany);

                return true;
            })
            ->once();
    }

    #[Test]
    public function stare_nieudane_zadania_sa_w_szeregu_widoczne_jako_liczba_a_nie_alarm(): void
    {
        // Odpowiednik czterech zadań z 9 września na produkcji: mają być
        // POLICZONE w szeregu czasowym, ale nie mają zapalać stanu.
        foreach (range(1, 4) as $ignored) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'x',
                'failed_at' => Carbon::parse('2026-09-09 14:05:04'),
            ]);
        }

        $dziennik = Log::spy();

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(0);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('spokojna', $kontekst['stan']);
                $this->assertSame(0, $kontekst['nieudane_w_oknie']);
                $this->assertSame(4, $kontekst['nieudane_razem']);

                return true;
            })
            ->once();
    }
}
