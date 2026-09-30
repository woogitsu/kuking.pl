<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Zapytania z żądania HTTP mają górną granicę czasu, a konsola (worker,
 * harmonogram, migracje) nie (issue #2290, audyt z 30.09, F3).
 *
 * Bez limitu jedno patologiczne zapytanie trzymało wątek repliki i połączenie
 * z bazą bez końca, a osoba widziała kręcącą się stronę.
 */
final class LimitCzasuZapytanHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/limit-zapytan', fn () => response((string) DB::selectOne("SELECT current_setting('statement_timeout') AS s")->s));
        Route::middleware('web')->get('/_test/limit-zapytan-nowe-polaczenie', fn () => response((string) DB::connection('pgsql_drugie_2290')->selectOne("SELECT current_setting('statement_timeout') AS s")->s));
        // Zapytanie wolniejsze niż limit. W transakcji, żeby przerwanie cofnęło
        // się do punktu zapisu, a nie zepsuło transakcji testu (na produkcji
        // żądanie nie ma zewnętrznej transakcji).
        Route::middleware('web')->get('/_test/wolne-zapytanie', function () {
            DB::transaction(fn () => DB::select('SELECT pg_sleep(3)'));

            return response('nie powinno dojść');
        });
    }

    public function test_zadanie_http_dostaje_limit_z_konfiguracji(): void
    {
        config(['kuking.polaczenia.limit_zapytania_http_ms' => 15000]);

        $this->assertSame('0', $this->ustawienie(), 'Kontrola: przed żądaniem połączenie testu nie ma limitu.');

        $this->get('/_test/limit-zapytan')->assertOk()->assertSeeText('15s');

        $this->assertSame('0', $this->ustawienie(), 'Limit żądania przeciekł poza żądanie — dostałby go kod po odpowiedzi.');
    }

    public function test_polaczenie_otwarte_w_trakcie_zadania_tez_dostaje_limit(): void
    {
        config(['kuking.polaczenia.limit_zapytania_http_ms' => 12000]);
        config(['database.connections.pgsql_drugie_2290' => config('database.connections.pgsql')]);

        $this->get('/_test/limit-zapytan-nowe-polaczenie')->assertOk()->assertSeeText('12s');

        DB::purge('pgsql_drugie_2290');
    }

    public function test_konsola_nie_dostaje_limitu(): void
    {
        config(['kuking.polaczenia.limit_zapytania_http_ms' => 15000]);
        $widziane = null;
        Artisan::command('kuking:test-limit-2290', function () use (&$widziane): void {
            $widziane = (string) DB::selectOne("SELECT current_setting('statement_timeout') AS s")->s;
        });

        $this->get('/_test/limit-zapytan')->assertOk()->assertSeeText('15s');
        Artisan::call('kuking:test-limit-2290');

        $this->assertSame('0', $widziane, 'Komenda konsoli (worker, harmonogram, migracje) dostała limit żądania HTTP.');
    }

    public function test_zero_wylacza_limit(): void
    {
        config(['kuking.polaczenia.limit_zapytania_http_ms' => 0]);

        $this->get('/_test/limit-zapytan')->assertOk()->assertSeeText('0');
    }

    public function test_przerwane_zapytanie_konczy_sie_polskim_ekranem_a_nie_wiszacym_zadaniem(): void
    {
        config(['kuking.polaczenia.limit_zapytania_http_ms' => 300]);

        $start = microtime(true);
        $odpowiedz = $this->get('/_test/wolne-zapytanie');
        $czas = microtime(true) - $start;

        $odpowiedz->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertSee('Strona ładuje się za długo')
            ->assertSee('Odczekaj pół minuty i otwórz ją jeszcze raz.')
            ->assertDontSee('nie powinno dojść');
        $this->assertLessThan(2.5, $czas, 'Żądanie czekało na koniec wolnego zapytania — limit nie zadziałał.');
        $this->assertSame('0', $this->ustawienie());
    }

    private function ustawienie(): string
    {
        return (string) DB::selectOne("SELECT current_setting('statement_timeout') AS s")->s;
    }
}
