<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/health`: trzy sprawdzenia dodane przy okazji issue #33 (audyt monitoringu).
 *
 * DLACZEGO TE TRZY RAZEM
 * Audyt #33 zapytał wprost, czy `/health` mówi prawdę o poczcie i o kolejce,
 * i czy `failed_jobs` w ogóle ktokolwiek widzi. Odpowiedź brzmiała „nie" na
 * oba pytania (D-042, `docs/DECISIONS.md`: „Jedyne miejsce, które w ogóle
 * liczy `failed_jobs`, to `kuking:sprawdz-poczte`, uruchamiane ręcznie") —
 * i to samo dotyczyło Turnstile: `check()` woła `Log::error`, ale na
 * produkcji `LOG_CHANNEL=stderr` (`.railway/railway.ts`), więc `Log::error`
 * NIE dociera do kanału `blad_webhook` (ten trzeba wywołać wprost —
 * `Log::channel('blad_webhook')`). Zanim ta zmiana powstała, żadna awaria
 * WYKRYWALNA TYLKO PRZEZ `/health` (Turnstile bez kluczy, poczta bez
 * transportu, zadania w `failed_jobs`) nie dzwoniła NIGDZIE poza treścią
 * odpowiedzi JSON, którą ktoś musiałby sam otworzyć.
 *
 * Ten plik dowodzi trzech rzeczy: (1) `/health` wykrywa te awarie, (2) robi
 * to WYŁĄCZNIE tam, gdzie ma sens (poczta tylko na produkcji — patrz
 * `HealthController::sprawdzPoczte()`), (3) wykryta awaria dzwoni na
 * `blad_webhook` BEZ treści wyjątku ani zawartości `failed_jobs`, i co
 * najwyżej raz na `WEBHOOK_ODSTEP_MINUT`.
 */
class HealthPocztaKolejkaIWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    // ------------------------------------------------------------------
    //  Poczta
    // ------------------------------------------------------------------

    /**
     * `MAIL_MAILER=array` jest domyślnym ustawieniem CAŁEJ suity testów
     * (`phpunit.xml`) — bez ograniczenia do produkcji ten check byłby
     * `degraded` w KAŻDYM innym teście, który trafia na `/health`.
     */
    public function test_poza_produkcja_domyslny_sterownik_testowy_nie_jest_awaria(): void
    {
        Artisan::call('storage:link');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.poczta.ok', true);
    }

    public function test_produkcja_z_dziennikiem_zamiast_poczty_jest_degraded_ale_nie_503(): void
    {
        Artisan::call('storage:link');

        config(['mail.default' => 'log']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $odpowiedz = $this->get('/health');

        // Świadomie 200: dokładnie ta sama zasada co przy Turnstile i mediach
        // wyżej w tym kontrolerze — healthcheck oddający 503 tu zbędnie
        // restartowałby kontener, co niczego by nie naprawiło.
        $odpowiedz->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.poczta.ok', false)
            ->assertJsonPath('checks.poczta.error', 'poczta_nie_wysyla')
            // Baza jest cała — sygnał dotyczy WYŁĄCZNIE poczty.
            ->assertJsonPath('checks.database.ok', true);

        $this->assertContains($odpowiedz->json('checks.poczta.error'), HealthController::POWODY);
    }

    public function test_produkcja_z_dzialajacym_transportem_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        config([
            'mail.default' => 'smtp',
            // Bez tego test mierzyłby zupełnie inną awarię: na produkcji,
            // bez kluczy, `sprawdzTurnstile()` (D-050) zgłosiłaby WŁASNY
            // powód i `status` byłby `degraded` niezależnie od poczty.
            'kuking.turnstile.klucz_publiczny' => 'test-klucz-publiczny',
            'kuking.turnstile.sekret' => 'test-sekret',
        ]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.poczta.ok', true);
    }

    // ------------------------------------------------------------------
    //  Kolejka / `failed_jobs`
    // ------------------------------------------------------------------

    public function test_pusta_tabela_failed_jobs_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.kolejka.ok', true);
    }

    /**
     * D-042: dziś ISTNIEJE realny sposób stracenia listu po cichu (limit
     * dobowy EmailLabs padający w środku wysyłki), a jedynym miejscem, które
     * w ogóle patrzy na `failed_jobs`, jest komenda uruchamiana ręcznie.
     * Ten test dowodzi, że `/health` — a więc i zewnętrzny monitoring —
     * teraz to widzi, BEZ logowania się na serwer.
     */
    public function test_nieudane_zadanie_w_failed_jobs_jest_widoczne_jako_degraded(): void
    {
        Artisan::call('storage:link');

        $this->wstawNieudaneZadanie();

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.kolejka.ok', false)
            ->assertJsonPath('checks.kolejka.error', 'zadania_nieudane');

        $this->assertContains($odpowiedz->json('checks.kolejka.error'), HealthController::POWODY);
    }

    /**
     * Sprawdza to samo, co `kuking:sprawdz-poczte` liczy dziś ręcznie
     * (`App\Console\Commands\SprawdzPoczte::stanKolejki()`) — niezależnie od
     * środowiska, bo `failed_jobs` puste w teście jest tak samo prawdziwe jak
     * puste na produkcji, a niepuste nigdzie nie jest stanem „normalnym dla
     * tego środowiska" (w odróżnieniu od `MAIL_MAILER=array` czy braku
     * kluczy Turnstile).
     */
    public function test_kontrola_kolejki_dziala_takze_poza_produkcja(): void
    {
        Artisan::call('storage:link');

        $this->wstawNieudaneZadanie();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('checks.kolejka.ok', false);
    }

    private function wstawNieudaneZadanie(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            // Treść jak w prawdziwym `failed_jobs.exception` — pełny ślad
            // stosu z adresem e-mail w środku (dokładnie ten kształt, który
            // D-042 opisuje jako ginący bez śladu). Testy niżej dowodzą, że
            // TA TREŚĆ nigdzie z `/health` ani z webhooka nie wychodzi.
            'exception' => "Illuminate\\Mail\\... adres: przepadly-list@example.com\nStack trace:\n#0 ...",
            'payload' => '{}',
            'failed_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    //  Webhook: awarie WYKRYWALNE TYLKO PRZEZ /health teraz dzwonią
    // ------------------------------------------------------------------

    public function test_bez_adresu_webhooka_healthcheck_nic_nie_wysyla(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => null]);
        Http::fake();

        $this->wstawNieudaneZadanie();

        $this->get('/health')->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        // Druga linia obrony po `bootstrap/app.php`/D-041: brak zmiennej
        // środowiskowej znaczy ZERO żądań HTTP, nawet gdy /health wykrywa
        // realną awarię.
        Http::assertNothingSent();
    }

    public function test_awaria_wykrywalna_tylko_przez_health_dzwoni_na_webhook(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake();

        $this->wstawNieudaneZadanie();

        $this->get('/health')->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tresc = (string) ($request['text'] ?? '');

            $this->assertStringContainsString('kolejka', $tresc);
            $this->assertStringContainsString('zadania_nieudane', $tresc);

            // WŁAŚCIWY DOWÓD: treść `failed_jobs.exception` (adres e-mail
            // z listu, który przepadł) NIE wychodzi na zewnątrz — na webhook
            // idzie wyłącznie nazwa kontroli i kod z `POWODY`.
            $this->assertStringNotContainsString('przepadly-list@example.com', $tresc);
            $this->assertStringNotContainsString('Stack trace', $tresc);

            return true;
        });
    }

    public function test_powtorzona_awaria_w_oknie_odstepu_nie_dzwoni_drugi_raz(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake();

        $this->wstawNieudaneZadanie();

        // Ten sam monitoring zewnętrzny odpytujący `/health` co kilka minut
        // (`docs/infra/INFRA_DECISION.md`) — DWA odpytania tej samej,
        // TRWAJĄCEJ awarii.
        $this->get('/health')->assertOk();
        $this->get('/health')->assertOk();

        Http::assertSentCount(1);
    }

    /**
     * Powrót do zdrowia kasuje odstęp — kolejna awaria (nawet chwilę później)
     * ma prawo zadzwonić od razu, zamiast czekać do końca okna poprzedniego
     * incydentu (patrz `HealthController::check()`).
     */
    public function test_po_powrocie_do_zdrowia_kolejna_awaria_dzwoni_od_razu(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake();

        $this->wstawNieudaneZadanie();
        $this->get('/health')->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        DB::table('failed_jobs')->truncate();
        $this->get('/health')->assertOk()->assertJsonPath('checks.kolejka.ok', true);

        $this->wstawNieudaneZadanie();
        $this->get('/health')->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        Http::assertSentCount(2);
    }

    /**
     * Awaria KRYTYCZNA (503) też dzwoni — do tej zmiany nawet całkowita
     * awaria bazy WYKRYTA PRZEZ `/health` nie docierała na webhook, bo
     * `check()` łapie wyjątek sam, w środku, i nigdy nie oddaje go dalej do
     * `$exceptions->report()` z `bootstrap/app.php`.
     */
    public function test_krytyczna_awaria_bazy_tez_dzwoni_na_webhook_bez_hasla_i_hosta(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake();

        $domyslna = (string) config('database.default');

        config([
            'database.connections.zepsuta' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'sekretna-nazwa-bazy',
                'username' => 'sekretny-uzytkownik',
                'password' => 'sekretne-haslo',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => 'zepsuta',
        ]);

        try {
            $this->get('/health')->assertStatus(503);
        } finally {
            config(['database.default' => $domyslna]);
            DB::purge('zepsuta');
        }

        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tresc = (string) ($request['text'] ?? '');

            $this->assertStringContainsString('baza_nie_odpowiada', $tresc);
            $this->assertStringNotContainsString('sekretna-nazwa-bazy', $tresc);
            $this->assertStringNotContainsString('sekretny-uzytkownik', $tresc);
            $this->assertStringNotContainsString('sekretne-haslo', $tresc);

            return true;
        });
    }
}
