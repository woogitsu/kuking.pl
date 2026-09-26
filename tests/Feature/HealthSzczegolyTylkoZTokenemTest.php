<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `/health` publicznie mówi tylko „działa / nie działa" i ma limit zapytań
 * (audyt A5-05, E-01).
 *
 * Wcześniej każdy w internecie dostawał pole `checks` z kodami w rodzaju
 * `turnstile_bez_kluczy` czy `limit_poczty_wyczerpany` — czyli informację,
 * KIEDY warto uderzyć w formularze — a każde wywołanie bez limitu zapisywało
 * obiekt do R2 i pytało bazę.
 *
 * Czego ta zmiana NIE rusza, i to jest pilnowane tu wprost: kodu HTTP (200 /
 * 503) i pola `status`. Z nich korzysta healthcheck Railway, test dymny
 * wdrożenia i `scripts/sprawdz-wdrozenie.sh`.
 */
class HealthSzczegolyTylkoZTokenemTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-zdrowia-do-testow';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('storage:link');
    }

    public function test_bez_tokenu_jest_status_i_kod_ale_nie_ma_szczegolow(): void
    {
        config(['kuking.health.token' => self::TOKEN]);

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()->assertJsonPath('status', 'ok')->assertJsonMissingPath('checks');
    }

    public function test_zly_token_nie_odslania_szczegolow(): void
    {
        config(['kuking.health.token' => self::TOKEN]);

        $this->get('/health', [HealthController::NAGLOWEK_TOKENU => 'zgadywany'])
            ->assertOk()
            ->assertJsonMissingPath('checks');
    }

    public function test_bez_tokenu_w_konfiguracji_szczegolow_nie_dostaje_nikt(): void
    {
        config(['kuking.health.token' => null]);

        // Pusty nagłówek nie może pasować do pustej konfiguracji.
        $this->get('/health', [HealthController::NAGLOWEK_TOKENU => ''])
            ->assertOk()
            ->assertJsonMissingPath('checks');
    }

    public function test_z_tokenem_szczegoly_sa(): void
    {
        // KONTROLA DODATNIA: bez niej „nie ma checks" przechodziłoby także
        // wtedy, gdyby pola nie było dla nikogo.
        config(['kuking.health.token' => self::TOKEN]);

        $this->get('/health', [HealthController::NAGLOWEK_TOKENU => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('checks.database.ok', true);
    }

    public function test_awaria_bazy_bez_tokenu_dalej_daje_503_i_status(): void
    {
        $this->zepsujBaze(function (): void {
            // Licznik limitu w cache NA BAZIE, jak na produkcji
            // (`CACHE_STORE=database`) — a baza leży.
            config(['cache.default' => 'database', 'cache.limiter' => null]);
            $this->app->forgetInstance(RateLimiter::class);
            $this->app->forgetInstance('cache');
            $this->app->forgetInstance('cache.store');

            $this->get('/health')
                ->assertStatus(503)
                ->assertJsonPath('status', 'degraded')
                ->assertJsonMissingPath('checks');
        });
    }

    public function test_za_czeste_pytania_dostaja_429(): void
    {
        [$ile] = explode(',', (string) config('kuking.limits.health'));

        for ($i = 0; $i < (int) $ile; $i++) {
            $this->get('/health')->assertOk();
        }

        $this->get('/health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_udana_probka_magazynu_jest_pamietana_krotko(): void
    {
        config(['kuking.health.token' => self::TOKEN, 'kuking.health.probka_magazynu_sekund' => 60]);
        $naglowki = [HealthController::NAGLOWEK_TOKENU => self::TOKEN];

        $this->get('/health', $naglowki)->assertJsonPath('checks.media.ok', true);

        // Magazyn przestaje przyjmować zapis. W oknie pamięci sonda go nie
        // dotyka — właśnie po to, żeby pętla `curl` nie pisała do R2.
        $this->zepsujDyskZdjec();

        // Zapisu nie było, więc nie ma `zapis_niemozliwy`. Sonda media dalej
        // porównuje `public/storage` z katalogiem dysku (to czysta
        // konfiguracja, bez zapytania do R2) — i ten zepsuty katalog wychodzi.
        $this->get('/health', $naglowki)->assertJsonPath('checks.media.error', 'droga_publiczna_gdzie_indziej');

        // Po oknie sonda próbuje znowu i awaria wychodzi.
        $this->travel(61)->seconds();

        $this->get('/health', $naglowki)
            ->assertJsonPath('checks.media.ok', false)
            ->assertJsonPath('checks.media.error', 'zapis_niemozliwy');
    }

    public function test_porazka_probki_nie_jest_pamietana(): void
    {
        config(['kuking.health.token' => self::TOKEN]);
        $naglowki = [HealthController::NAGLOWEK_TOKENU => self::TOKEN];
        $nazwa = (string) config('kuking.media.disk');
        $zdrowy = config("filesystems.disks.{$nazwa}");

        $this->zepsujDyskZdjec();
        $this->get('/health', $naglowki)->assertJsonPath('checks.media.ok', false);

        // Ten sam dysk wraca do zdrowia — i sonda widzi to od razu.
        config(["filesystems.disks.{$nazwa}" => $zdrowy]);
        Storage::forgetDisk($nazwa);

        $this->get('/health', $naglowki)->assertJsonPath('checks.media.ok', true);
    }

    /** Ten sam dysk (ta sama nazwa) przestaje przyjmować zapis. */
    private function zepsujDyskZdjec(): void
    {
        $nazwa = (string) config('kuking.media.disk');

        config(["filesystems.disks.{$nazwa}" => [
            'driver' => 'local',
            'root' => '/proc/nie-ma-takiego-katalogu',
            'throw' => true,
        ]]);
        Storage::forgetDisk($nazwa);
    }

    private function zepsujBaze(\Closure $cialo): void
    {
        $domyslna = (string) config('database.default');

        config([
            'database.connections.zepsuta' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'nie-ma',
                'username' => 'nie-ma',
                'password' => 'nie-ma',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => 'zepsuta',
        ]);

        try {
            $cialo();
        } finally {
            config(['database.default' => $domyslna, 'cache.default' => 'array']);
            DB::purge('zepsuta');
        }
    }
}
