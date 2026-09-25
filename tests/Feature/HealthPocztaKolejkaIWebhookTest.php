<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/health`: trzy sprawdzenia dodane przy okazji issue #33 (audyt monitoringu).
 *
 * DLACZEGO TE TRZY RAZEM
 * Audyt #33 zapytał wprost, czy `/health` mówi prawdę o poczcie i o kolejce,
 * i czy `failed_jobs` w ogóle ktokolwiek widzi. Odpowiedź brzmiała „nie" na
 * oba pytania (D-057 §4, `docs/DECISIONS.md`: „Jedyne miejsce, które w ogóle
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

    /** @var list<MessageLogged> */
    private array $wpisyDziennika = [];

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

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.poczta.ok', true);
    }

    public function test_produkcja_z_dziennikiem_zamiast_poczty_jest_degraded_ale_nie_503(): void
    {
        Artisan::call('storage:link');

        config(['mail.default' => 'log']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $odpowiedz = $this->zdrowieZeSzczegolami();

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
            // `/health` sprawdza teraz także dwie dodatkowe drogi wejścia
            // (`google`, `facebook`, issue #258/#259): na produkcji, z funkcją
            // włączoną i bez kluczy, każda z nich zgłasza WŁASNY powód
            // i `status` byłby `degraded` niezależnie od tego, co ten test
            // mierzy. Kluczy w testach nie ma i mieć nie musi, więc wyłączamy
            // je świadomie — dokładnie tym przełącznikiem, którym wyłącza się
            // je na produkcji.
            'kuking.google.wlaczone' => false,
            'kuking.facebook.wlaczone' => false,
            // Analityka odwiedzin (D-092) zapala się na produkcji z trzeciego,
            // własnego powodu: polityka prywatności ją obiecuje, a tokenu
            // w testach nie ma. Przełącznika „wyłącz" tu nie ma i mieć nie ma
            // (obietnica stoi w dokumencie prawnym, nie w konfiguracji), więc
            // uciszamy ją jedyną uczciwą drogą — udawanym tokenem.
            'kuking.analytics.cloudflare.token' => 'udawany-token-analityki',
            // Czyszczenie cache CDN (audyt G-03) zapala na produkcji własny
            // sygnał `czyszczenie_cdn_wylaczone`, gdy nie ma `CLOUDFLARE_ZONE_ID`
            // i `CLOUDFLARE_PURGE_TOKEN` — a w testach ich nie ma i mieć nie
            // musi. Uciszamy go udawaną parą, żeby ten test mierzył swoje.
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => 'udawany-token-czyszczenia',
        ]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->zdrowieZeSzczegolami()
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

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.kolejka.ok', true);
    }

    /**
     * D-057 §4: dziś ISTNIEJE realny sposób stracenia listu po cichu (limit
     * dobowy EmailLabs padający w środku wysyłki), a jedynym miejscem, które
     * w ogóle patrzy na `failed_jobs`, jest komenda uruchamiana ręcznie.
     * Ten test dowodzi, że `/health` — a więc i zewnętrzny monitoring —
     * teraz to widzi, BEZ logowania się na serwer.
     */
    public function test_nieudane_zadanie_w_failed_jobs_jest_widoczne_jako_degraded(): void
    {
        Artisan::call('storage:link');

        $this->wstawNieudaneZadanie();

        $odpowiedz = $this->zdrowieZeSzczegolami();

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

        $this->zdrowieZeSzczegolami()
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
            // D-057 §4 opisuje jako ginący bez śladu). Testy niżej dowodzą, że
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

        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

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

        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

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
        $this->zdrowieZeSzczegolami()->assertOk();
        $this->zdrowieZeSzczegolami()->assertOk();

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
        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        DB::table('failed_jobs')->truncate();
        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', true);

        $this->wstawNieudaneZadanie();
        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

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
            $this->zdrowieZeSzczegolami()->assertStatus(503);
        } finally {
            config(['database.default' => $domyslna]);
            DB::purge('zepsuta');
        }

        // Padnięta baza wywraca WIĘCEJ NIŻ JEDNĄ sondę (`database`,
        // `migrations`, a od #253 także `listy`, bo nie da się odczytać
        // `mail_failures`), więc każda z nich dzwoni osobno. Sprawdzamy
        // więc dwie rzeczy osobno: że wśród wiadomości jest ta o bazie,
        // i że ŻADNA z nich nie niesie danych połączenia.
        $wiadomosci = Http::recorded()
            ->map(static fn (array $para): string => (string) ($para[0]['text'] ?? ''))
            ->all();

        $this->assertNotEmpty($wiadomosci, 'Awaria krytyczna miała zadzwonić na webhook.');

        $this->assertTrue(
            collect($wiadomosci)->contains(fn (string $tresc): bool => str_contains($tresc, 'baza_nie_odpowiada')),
            'Wśród wiadomości nie ma tej o niedostępnej bazie.',
        );

        foreach ($wiadomosci as $tresc) {
            $this->assertStringNotContainsString('sekretna-nazwa-bazy', $tresc);
            $this->assertStringNotContainsString('sekretny-uzytkownik', $tresc);
            $this->assertStringNotContainsString('sekretne-haslo', $tresc);
        }
    }

    // ------------------------------------------------------------------
    //  Webhook, który NIE ODPOWIADA — czyli czy to nie jest cichy try/catch
    // ------------------------------------------------------------------

    /**
     * DZWONEK, KTÓRY NIE ZADZWONIŁ, NIE KUPUJE CISZY — i zostawia ślad.
     *
     * To jest najbardziej podstępna gałąź w całym tym mechanizmie.
     * `WebhookBleduHandler::write()` nie ma prawa rzucić (inaczej człowiek na
     * stronie zamiast błędu 500 dostawałby wyjątek z samego mechanizmu
     * powiadamiania), a nieudane żądanie HTTP wyjątku NAWET NIE RZUCA:
     * Discord z odwołanym webhookiem odpowiada 401/404, a klient Laravela bez
     * `throw()` oddaje to jako zwykłą odpowiedź. Bez tego testu wyglądałoby
     * to więc tak: pierwsze odpytanie `/health` „dzwoni", wiadomość przepada,
     * odstęp 30 minut jest już zajęty — i o trwającej awarii nie dowiaduje
     * się nikt, przez pół godziny, w sposób nieodróżnialny od sukcesu.
     *
     * Dwie asercje DODATNIE: fakt niedodzwonienia się jest w dzienniku
     * serwera, a następne odpytanie dzwoni jeszcze raz.
     */
    public function test_webhook_ktory_odpowiedzial_bledem_nie_wycisza_i_zostawia_slad(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake([self::ADRES_WEBHOOKA => Http::response('nie ma takiego webhooka', 404)]);

        $this->nasluchujDziennika();

        $this->wstawNieudaneZadanie();

        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        $this->assertTrue(
            $this->wDziennikuJest('Nie udało się zadzwonić na webhook błędów'),
            'Fakt niedodzwonienia się musi gdzieś zostać — inaczej „nie rzucamy" znaczy „milczymy".',
        );

        // Ta sama, wciąż trwająca awaria: skoro poprzedni dzwonek nie doszedł,
        // odstęp nie należy się i drugie odpytanie ma zadzwonić.
        $this->zdrowieZeSzczegolami()->assertOk();

        Http::assertSentCount(2);
    }

    /**
     * To samo, ale gdy webhook nie odpowiada WCALE (zerwane połączenie,
     * padnięty DNS) — czyli gałąź `catch`, nie gałąź „HTTP 4xx".
     *
     * Osobny test i osobny sabotaż, bo to są dwie różne gałęzie warunku
     * (`docs/PULAPKI_TESTOW.md` §3b), a przy pustym `catch` obie wyglądały
     * identycznie jak sukces.
     */
    public function test_webhook_ktory_nie_odpowiada_wcale_tez_nie_wycisza(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake([
            self::ADRES_WEBHOOKA => static function (): never {
                throw new ConnectionException('Nie udało się połączyć.');
            },
        ]);

        $this->nasluchujDziennika();

        $this->wstawNieudaneZadanie();

        // Brak wyjątku z `/health` JEST tu asercją: awaria mechanizmu
        // powiadamiania nie ma prawa wywrócić samego healthchecku.
        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);

        $this->assertSame(
            1,
            $this->ileWDzienniku('Nie udało się zadzwonić na webhook błędów'),
            'Zerwane połączenie z webhookiem też ma zostać w dzienniku serwera.',
        );

        // Drugie odpytanie tej samej, trwającej awarii. Liczymy WPISY, a nie
        // żądania: `Http::fake()` nie rejestruje próby, która skończyła się
        // wyjątkiem połączenia, więc `assertSentCount()` pokazywałaby tu zero
        // niezależnie od tego, czy odstęp został oddany — czyli byłaby
        // asercją o niczym.
        $this->zdrowieZeSzczegolami()->assertOk();

        $this->assertSame(
            2,
            $this->ileWDzienniku('Nie udało się zadzwonić na webhook błędów'),
            'Skoro pierwszy dzwonek nie doszedł, odstęp się nie należy i drugie odpytanie ma próbować znowu.',
        );
    }

    /**
     * KONTROLA DODATNIA DO DWÓCH TESTÓW WYŻEJ: gdy dzwonek DOSZEDŁ, odstęp
     * obowiązuje. Bez tej pary „oddajemy odstęp przy porażce" mogłoby znaczyć
     * „nie ma żadnego odstępu", czyli zamianę ciszy na zalanie kanału
     * (`docs/PULAPKI_TESTOW.md` §4).
     */
    public function test_dzwonek_ktory_doszedl_wycisza_na_czas_odstepu(): void
    {
        Artisan::call('storage:link');

        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Http::fake([self::ADRES_WEBHOOKA => Http::response('', 204)]);

        $this->wstawNieudaneZadanie();

        $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('checks.kolejka.ok', false);
        $this->zdrowieZeSzczegolami()->assertOk();

        Http::assertSentCount(1);
    }

    private function nasluchujDziennika(): void
    {
        $this->wpisyDziennika = [];

        Log::listen(function (MessageLogged $wpis): void {
            $this->wpisyDziennika[] = $wpis;
        });
    }

    private function wDziennikuJest(string $fragment): bool
    {
        return $this->ileWDzienniku($fragment) > 0;
    }

    private function ileWDzienniku(string $fragment): int
    {
        return count(array_filter(
            $this->wpisyDziennika,
            static fn (MessageLogged $wpis): bool => str_contains($wpis->message, $fragment),
        ));
    }
}
