<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Polaczenia\StanPolaczenBazy;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * `kuking:budzet-polaczen --probki=N` — szczyt w oknie, którego godzinna
 * czujka nie widzi (issue #598).
 *
 * Harmonogram próbkuje raz na godzinę, o :25. Okno wdrożenia trwa minutę–dwie,
 * więc szczyt wdrożeniowy z `docs/DATABASE.md` §598 C (13) był dotąd tylko
 * policzony. Ten tryb pozwala właścicielowi go ZMIERZYĆ — i ten plik pilnuje,
 * że tryb podaje maksimum okna, a nie ostatnią próbkę, i że nigdy nie dzwoni.
 *
 * @bez-kontroli-dodatniej Jedyny odczyt pliku to `scripts/szczyt-polaczen.sql`, wykonywany na PostgreSQL; asercje dotyczą wyniku zapytania i zachowania komendy, nie tekstu źródła.
 */
class ProbkowanieSzczytuPolaczenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.polaczenia.budzet_szczytowy', 16);
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 50);
        config()->set('kuking.polaczenia.prog_krytyczny', 125);

        Sleep::fake();
    }

    private function szpiegKanaluPomiarow(): MockInterface
    {
        $kanal = Mockery::spy(LoggerInterface::class);

        Log::shouldReceive('channel')->with('pomiary')->andReturn($kanal);

        return $kanal;
    }

    /**
     * Atrapa serwera o zadanej liczbie zajętych backendów — `StanPolaczenBazy`
     * jest `final`, więc podmieniamy połączenie, a nie klasę. Dzięki temu
     * progi i ocena stanu idą PRAWDZIWYM kodem, tak jak na produkcji.
     * `null` = serwer nie odpowiada na zapytanie o liczby.
     */
    private function atrapaSerwera(?int $zajete): Connection
    {
        $polaczenie = Mockery::mock(Connection::class);
        $polaczenie->shouldReceive('getDriverName')->andReturn('pgsql');
        $polaczenie->shouldReceive('select')->andReturn([
            (object) ['name' => 'max_connections', 'setting' => '500'],
            (object) ['name' => 'superuser_reserved_connections', 'setting' => '3'],
            (object) ['name' => 'reserved_connections', 'setting' => '0'],
        ]);

        if ($zajete === null) {
            $polaczenie->shouldReceive('selectOne')->andThrow(new \RuntimeException('brak odpowiedzi'));

            return $polaczenie;
        }

        $polaczenie->shouldReceive('selectOne')->andReturn((object) [
            'nazwa_bazy' => 'kuking_atrapa',
            'serwer' => $zajete,
            'baza' => $zajete,
            'bezczynne' => 0,
            'aktywne' => $zajete,
            'w_transakcji' => 0,
        ]);

        return $polaczenie;
    }

    private function kolejnePomiary(?int ...$zajete): void
    {
        DB::partialMock()->shouldReceive('connection')->withNoArgs()->andReturn(
            ...array_map(fn (?int $z): Connection => $this->atrapaSerwera($z), $zajete),
        );
    }

    #[Test]
    public function probkowanie_mierzy_prawdziwy_serwer_i_zapisuje_jedna_linie_podsumowania(): void
    {
        $dziennik = $this->szpiegKanaluPomiarow();

        $this->artisan('kuking:budzet-polaczen', ['--probki' => 3, '--odstep' => 2])
            ->expectsOutputToContain('próbka 3/3')
            ->assertExitCode(0);

        // Między trzema próbkami są DWIE przerwy, nie trzy — ostatnia próbka
        // nie każe czekać na nic.
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
            Sleep::for(2)->seconds(),
        ]);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('kuking:budzet-polaczen:szczyt', $wiadomosc);
                $this->assertSame(3, $kontekst['probki']);
                $this->assertSame(0, $kontekst['nieudane']);
                $this->assertGreaterThanOrEqual(1, $kontekst['szczyt_zajete_serwer']);
                $this->assertArrayNotHasKey('baza', $kontekst);

                return true;
            })
            ->once();
    }

    #[Test]
    public function szczyt_to_maksimum_okna_a_nie_ostatnia_probka(): void
    {
        $this->kolejnePomiary(6, 130, 7);

        $dziennik = $this->szpiegKanaluPomiarow();

        // Niezerowy kod: w oknie był stan krytyczny, choć ostatnia próbka
        // (7) jest spokojna. Tryb, który oddawałby ostatnią, przegapiłby pik.
        $this->artisan('kuking:budzet-polaczen', ['--probki' => 3])->assertExitCode(1);

        $dziennik->shouldHaveReceived('info')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame(130, $kontekst['szczyt_zajete_serwer']);
                $this->assertSame(StanPolaczenBazy::KRYTYCZNY, $kontekst['stan']);

                return true;
            })
            ->once();
    }

    private function wlaczKanalAlarmu(): void
    {
        config()->set('logging.channels.blad_webhook.url', 'https://przyklad.test/webhook-598');
        Log::forgetChannel('blad_webhook');
        Cache::flush();
        Http::fake(['https://przyklad.test/*' => Http::response('ok', 200)]);
    }

    #[Test]
    public function kontrola_dodatnia_zwykly_przebieg_w_tym_samym_stanie_dzwoni(): void
    {
        // Bez tego testu „nic nie wysłano" niżej mogłoby znaczyć tylko tyle,
        // że kanał alarmu w teście jest martwy.
        $this->wlaczKanalAlarmu();
        $this->kolejnePomiary(130);

        $this->artisan('kuking:budzet-polaczen')->assertExitCode(1);

        Http::assertSentCount(1);
    }

    #[Test]
    public function probkowanie_nigdy_nie_dzwoni_nawet_w_stanie_krytycznym(): void
    {
        $this->wlaczKanalAlarmu();
        $this->kolejnePomiary(130, 130, 130);

        $this->artisan('kuking:budzet-polaczen', ['--probki' => 3])->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function nieudana_probka_nie_daje_zielonego_wyniku(): void
    {
        $this->kolejnePomiary(5, null);

        $this->szpiegKanaluPomiarow();

        // Dziura w oknie znaczy, że szczytu mogło nie być widać — to nie jest
        // „zapas jest".
        $this->artisan('kuking:budzet-polaczen', ['--probki' => 2])
            ->expectsOutputToContain('odczyt nieudany')
            ->assertExitCode(1);
    }

    #[Test]
    public function zapytanie_do_psql_z_instrukcji_liczy_to_samo_co_czujka(): void
    {
        // Właściciel wkleja `scripts/szczyt-polaczen.sql` w psql usługi
        // Postgres (docs/DATABASE.md §598 G). Zapytanie ma działać na
        // prawdziwym serwerze i dawać tę samą liczbę co `StanPolaczenBazy` —
        // inaczej dwa pomiary tego samego szczytu mówiłyby co innego.
        $wiersz = DB::selectOne((string) file_get_contents(base_path('scripts/szczyt-polaczen.sql')));
        $czujka = app(StanPolaczenBazy::class)->sprawdz();

        $this->assertSame($czujka['max_connections'], (int) $wiersz->max_connections);
        $this->assertGreaterThanOrEqual(1, (int) $wiersz->zajete_serwer);
        $this->assertEqualsWithDelta($czujka['zajete_serwer'], (int) $wiersz->zajete_serwer, 2);
        $this->assertGreaterThanOrEqual(1, (int) $wiersz->aktywne);
    }
}
