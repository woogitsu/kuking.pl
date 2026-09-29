<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * KONTRAKT ODPOWIEDZI `/health` — zamrożony kształt, nie wartości.
 *
 * Z `/health` korzystają konsumenci, których nie widać z poziomu kontrolera:
 * healthcheck Railway (`.railway/railway.ts`: `healthcheckPath: "/health"`,
 * timeout 180 s na produkcji i 300 s poza nią; wystarczy kod 2xx), test dymny
 * `scripts/sprawdz-wdrozenie.sh` (czyta `status` i, z tokenem, `checks`),
 * zewnętrzny monitor (Better Stack: HTTP 200 + słowo kluczowe `"status":"ok"`,
 * `docs/infra/MONITORING_BLEDOW.md`) oraz `docker/healthcheck.sh` (`php -r`
 * z `file_get_contents`, czyli kod 503/429 = porażka). Ten plik jest PIERWSZYM
 * etapem porządkowania `HealthController` (1377 linii): zanim ktokolwiek go
 * rozbierze, kształt odpowiedzi ma być przypięty testem, żeby refaktor nie
 * zmienił go po cichu.
 *
 * CO JEST ZAMROŻONE
 *  - kod HTTP: 200 (ok albo degraded), 503 (tylko `database`/`migrations`),
 *    429 (limit zapytań);
 *  - zbiór kluczy na każdym poziomie: bez tokenu `status`, `app`,
 *    `environment`, `time`; z tokenem dodatkowo `checks` z DOKŁADNIE
 *    szesnastoma nazwanymi sondami; sonda to `ok` (bool) i — tylko gdy `ok`
 *    jest false — `error` (kod z `HealthController::POWODY`);
 *  - typy pól, format `time` (ISO 8601), `status` ∈ {ok, degraded};
 *  - nagłówki: `Content-Type: application/json`, `Cache-Control` z `no-store`
 *    i `private` (nigdy `public`), `Retry-After` przy 429;
 *  - `/up` (frameworkowe „proces żyje", bez bazy) odpowiada 200;
 *  - brak sekretów w odpowiedzi.
 *
 * CZEGO TU CELOWO NIE MA
 * Wartości zmiennych (`time`, `app`, `environment`, `X-Request-Id`, nonce CSP)
 * ani `Set-Cookie`: `/health` idzie dziś grupą `web`, więc odpowiedź niesie
 * ciasteczka sesji i XSRF, a `/up` nie. To NIE jest kontrakt, tylko skutek
 * uboczny (patrz raport przy tym teście) — zamrożenie go utrudniłoby jego
 * usunięcie. Test czyta wyłącznie odpowiedzi HTTP, nie kod źródłowy.
 *
 * Refs #2212. `detectEnvironment` w testach produkcyjnych nie przecieka: każdy test
 * dostaje świeżą aplikację (`TestCase::setUp`), więc środowisko wraca do `testing`.
 */
class HealthKontraktOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-kontraktu-zdrowia';

    /** Klucze odpowiedzi dla każdego. */
    private const KLUCZE_PUBLICZNE = ['status', 'app', 'environment', 'time'];

    /** Klucze odpowiedzi z tokenem szczegółów, w kolejności kodu. */
    private const KLUCZE_Z_TOKENEM = ['status', 'app', 'environment', 'time', 'checks'];

    /** Nazwy sond w `checks`, w kolejności kodu (kolejność też jest częścią kształtu JSON-a). */
    private const SONDY = [
        'database', 'migrations', 'media', 'turnstile', 'google', 'facebook',
        'analityka', 'poczta', 'kolejka', 'listy', 'cdn', 'alarmy_moderacji',
        'cdn_zalegle', 'magazyn', 'debug', 'sesja',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('storage:link');
        config(['kuking.health.token' => self::TOKEN]);
    }

    // ------------------------------------------------------------------
    //  Stan: zdrowy
    // ------------------------------------------------------------------

    public function test_zdrowy_bez_tokenu_200_ok_i_tylko_cztery_klucze(): void
    {
        $odpowiedz = $this->get('/health');

        $odpowiedz->assertStatus(200);
        $this->assertKsztaltPubliczny($odpowiedz, 'ok');
        $this->assertNaglowkiJson($odpowiedz);
        $this->assertSame(self::KLUCZE_PUBLICZNE, array_keys($odpowiedz->json()));
    }

    public function test_zdrowy_z_tokenem_200_ok_i_szesnascie_sond_z_samym_ok(): void
    {
        $odpowiedz = $this->zTokenem();

        $odpowiedz->assertStatus(200);
        $this->assertKsztaltZTokenem($odpowiedz, 'ok');
        $this->assertNaglowkiJson($odpowiedz);

        foreach (self::SONDY as $sonda) {
            $this->assertSame(['ok' => true], $odpowiedz->json("checks.{$sonda}"), "Zdrowa sonda „{$sonda}\" niesie wyłącznie ok=true.");
        }
    }

    public function test_zly_i_pusty_token_daja_ksztalt_publiczny(): void
    {
        foreach (['zgadywany', '', self::TOKEN.'x'] as $podany) {
            $odpowiedz = $this->get('/health', [HealthController::NAGLOWEK_TOKENU => $podany]);

            $odpowiedz->assertStatus(200);
            $this->assertKsztaltPubliczny($odpowiedz, 'ok');
        }
    }

    // ------------------------------------------------------------------
    //  Stan: baza niedostępna → failed (503)
    // ------------------------------------------------------------------

    public function test_baza_niedostepna_bez_tokenu_503_degraded_i_cztery_klucze(): void
    {
        $this->zepsujBaze(function (): void {
            $odpowiedz = $this->get('/health');

            $odpowiedz->assertStatus(503);
            $this->assertKsztaltPubliczny($odpowiedz, 'degraded');
            $this->assertNaglowkiJson($odpowiedz);
            $this->assertBezSekretow($odpowiedz);
        });
    }

    public function test_baza_niedostepna_z_tokenem_503_i_obie_krytyczne_sondy_padaja_kodem(): void
    {
        $this->zepsujBaze(function (): void {
            $odpowiedz = $this->zTokenem();

            $odpowiedz->assertStatus(503);
            $this->assertKsztaltZTokenem($odpowiedz, 'degraded');
            $this->assertNaglowkiJson($odpowiedz);
            $this->assertBezSekretow($odpowiedz);

            $odpowiedz->assertJsonPath('checks.database', ['ok' => false, 'error' => 'baza_nie_odpowiada']);
            $odpowiedz->assertJsonPath('checks.migrations', ['ok' => false, 'error' => 'baza_nie_odpowiada']);
        });
    }

    public function test_brak_migracji_to_503_z_kodem_brak_migracji(): void
    {
        // Wiersz zniknie razem z transakcją testu (RefreshDatabase).
        DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();

        $bez = $this->get('/health');
        $bez->assertStatus(503);
        $this->assertKsztaltPubliczny($bez, 'degraded');

        $z = $this->zTokenem();
        $z->assertStatus(503);
        $this->assertKsztaltZTokenem($z, 'degraded');
        $z->assertJsonPath('checks.migrations', ['ok' => false, 'error' => 'brak_migracji']);
        $z->assertJsonPath('checks.database', ['ok' => true]);

        // Nazwy plików migracji (kształt schematu) nie wychodzą nigdy.
        $this->assertStringNotContainsString('create_', (string) $bez->getContent());
        $this->assertStringNotContainsString('create_', (string) $z->getContent());
    }

    // ------------------------------------------------------------------
    //  Stan: sonda ostrzegawcza → degraded (200, NIGDY 503)
    // ------------------------------------------------------------------

    public function test_nieudane_zadanie_kolejki_to_200_degraded(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"tajny":"ladunek-zadania"}',
            'exception' => 'RuntimeException: tajny-slad-stosu',
            'failed_at' => now(),
        ]);

        $bez = $this->get('/health');
        $bez->assertStatus(200);
        $this->assertKsztaltPubliczny($bez, 'degraded');
        $this->assertNaglowkiJson($bez);

        $z = $this->zTokenem();
        $z->assertStatus(200);
        $this->assertKsztaltZTokenem($z, 'degraded');
        $z->assertJsonPath('checks.kolejka', ['ok' => false, 'error' => 'zadania_nieudane']);

        // Krytyczne zostają zdrowe: degraded nie może udawać awarii bazy.
        $z->assertJsonPath('checks.database', ['ok' => true]);
        $z->assertJsonPath('checks.migrations', ['ok' => true]);

        foreach ([$bez, $z] as $odpowiedz) {
            $this->assertStringNotContainsString('tajny', (string) $odpowiedz->getContent());
        }
    }

    public function test_dysk_zdjec_niedostepny_to_200_degraded_z_kodem_zapisu(): void
    {
        $this->zepsujDyskZdjec();

        $bez = $this->get('/health');
        $bez->assertStatus(200);
        $this->assertKsztaltPubliczny($bez, 'degraded');

        $z = $this->zTokenem();
        $z->assertStatus(200);
        $this->assertKsztaltZTokenem($z, 'degraded');
        $this->assertSame(false, $z->json('checks.media.ok'));
        $this->assertContains($z->json('checks.media.error'), HealthController::POWODY);
        $this->assertStringNotContainsString('/proc/', (string) $z->getContent());
    }

    public function test_produkcja_z_dziennikiem_zamiast_poczty_i_zlym_hostem_r2_to_200_degraded(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'app.debug' => false,
            'session.secure' => true,
            'mail.default' => 'log',
            'filesystems.disks.r2_probny' => [
                'driver' => 'r2',
                'endpoint' => 'https://tajne-konto-r2.example.test',
                'key' => 'tajny-klucz-r2',
                'secret' => 'tajny-sekret-r2',
            ],
        ]);

        $z = $this->zTokenem();
        $z->assertStatus(200);
        $this->assertKsztaltZTokenem($z, 'degraded');
        $z->assertJsonPath('checks.poczta', ['ok' => false, 'error' => 'poczta_nie_wysyla']);
        $z->assertJsonPath('checks.magazyn', ['ok' => false, 'error' => 'magazyn_r2_zly_host']);

        $bez = $this->get('/health');
        $bez->assertStatus(200);
        $this->assertKsztaltPubliczny($bez, 'degraded');

        foreach ([$bez, $z] as $odpowiedz) {
            foreach (['tajne-konto-r2', 'tajny-klucz-r2', 'tajny-sekret-r2'] as $sekret) {
                $this->assertStringNotContainsString($sekret, (string) $odpowiedz->getContent());
            }
        }
    }

    public function test_produkcja_z_debugiem_i_ciasteczkiem_bez_secure_to_200_degraded(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.debug' => true, 'session.secure' => false, 'mail.default' => 'smtp']);

        $z = $this->zTokenem();
        $z->assertStatus(200);
        $this->assertKsztaltZTokenem($z, 'degraded');
        $z->assertJsonPath('checks.debug', ['ok' => false, 'error' => 'debug_wlaczony']);
        $z->assertJsonPath('checks.sesja', ['ok' => false, 'error' => 'sesja_bez_secure']);
    }

    // ------------------------------------------------------------------
    //  Limit zapytań i /up
    // ------------------------------------------------------------------

    public function test_limit_zapytan_oddaje_429_z_message_i_retry_after(): void
    {
        config(['kuking.limits.health' => '3,1']);

        for ($i = 0; $i < 3; $i++) {
            $this->get('/health')->assertStatus(200);
        }

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertStatus(429);
        $this->assertNaglowkiJson($odpowiedz);
        $this->assertSame(['message'], array_keys($odpowiedz->json()));
        $this->assertIsString($odpowiedz->json('message'));
        $this->assertNotSame('', $odpowiedz->json('message'));

        $retry = $odpowiedz->headers->get('Retry-After');
        $this->assertNotNull($retry);
        $this->assertMatchesRegularExpression('/^[1-9]\d*$/', $retry);
        $this->assertLessThanOrEqual(60, (int) $retry);
    }

    public function test_limit_liczy_sie_po_adresie_a_nie_dla_wszystkich(): void
    {
        config(['kuking.limits.health' => '1,1']);

        $this->get('/health')->assertStatus(200);
        $this->get('/health')->assertStatus(429);

        // Inny adres ma własny licznik (inaczej jeden monitor blokowałby Railway).
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/health')->assertStatus(200);
    }

    public function test_up_odpowiada_200_bez_bazy_i_nie_jest_kontraktem_json(): void
    {
        $this->zepsujBaze(function (): void {
            $up = $this->get('/up');

            $up->assertStatus(200);
            $this->assertStringNotContainsString('application/json', (string) $up->headers->get('Content-Type'));

            // ...gdy /health w tej samej chwili mówi 503: to są dwa RÓŻNE sygnały.
            $this->get('/health')->assertStatus(503);
        });
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    private function zTokenem(): TestResponse
    {
        return $this->get('/health', [HealthController::NAGLOWEK_TOKENU => self::TOKEN]);
    }

    /** Kształt bez tokenu: dokładnie cztery klucze, bez `checks`, typy i format. */
    private function assertKsztaltPubliczny(TestResponse $odpowiedz, string $status): void
    {
        $json = $odpowiedz->json();

        $this->assertIsArray($json);
        $this->assertSame(self::KLUCZE_PUBLICZNE, array_keys($json), 'Zbiór kluczy publicznej odpowiedzi /health się zmienił.');
        $this->assertSame($status, $json['status']);
        $this->assertIsString($json['app']);
        $this->assertIsString($json['environment']);
        $this->assertIsString($json['time']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $json['time']), 'time nie jest w formacie ISO 8601.');
        $this->assertContains($json['status'], ['ok', 'degraded']);
    }

    /** Kształt z tokenem: pięć kluczy, `checks` z szesnastoma sondami w tej kolejności. */
    private function assertKsztaltZTokenem(TestResponse $odpowiedz, string $status): void
    {
        $json = $odpowiedz->json();

        $this->assertIsArray($json);
        $this->assertSame(self::KLUCZE_Z_TOKENEM, array_keys($json), 'Zbiór kluczy odpowiedzi /health z tokenem się zmienił.');
        $this->assertSame($status, $json['status']);
        $this->assertIsString($json['app']);
        $this->assertIsString($json['environment']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $json['time']));
        $this->assertIsArray($json['checks']);
        $this->assertSame(self::SONDY, array_keys($json['checks']), 'Nazwy albo kolejność sond w checks się zmieniły.');

        $wszystkieOk = true;

        foreach ($json['checks'] as $nazwa => $sonda) {
            $this->assertIsArray($sonda, "Sonda „{$nazwa}\" nie jest obiektem.");
            $this->assertIsBool($sonda['ok'] ?? null, "Sonda „{$nazwa}\" nie ma pola ok typu bool.");

            if ($sonda['ok']) {
                $this->assertSame(['ok'], array_keys($sonda), "Zdrowa sonda „{$nazwa}\" niesie coś poza ok.");

                continue;
            }

            $wszystkieOk = false;
            $this->assertSame(['ok', 'error'], array_keys($sonda), "Niezdrowa sonda „{$nazwa}\" ma inny zbiór kluczy niż ok+error.");
            $this->assertIsString($sonda['error']);
            $this->assertContains($sonda['error'], HealthController::POWODY, "Sonda „{$nazwa}\" zwróciła kod spoza zamkniętego zbioru.");
        }

        // `status` wynika z sond: ok tylko gdy KAŻDA jest ok.
        $this->assertSame($wszystkieOk ? 'ok' : 'degraded', $json['status']);
    }

    private function assertNaglowkiJson(TestResponse $odpowiedz): void
    {
        $this->assertStringStartsWith('application/json', (string) $odpowiedz->headers->get('Content-Type'));

        $cache = strtolower((string) $odpowiedz->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $cache, '/health nie może być przechowywany przez pośredników.');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }

    /** Żaden host, port, nazwa bazy, użytkownik, hasło, klucz ani adres e-mail. */
    private function assertBezSekretow(TestResponse $odpowiedz): void
    {
        $tresc = (string) $odpowiedz->getContent();

        $sekrety = [
            '127.0.0.1', 'tajna-baza-kontraktu', 'tajny-uzytkownik-kontraktu', 'tajne-haslo-kontraktu',
            'SQLSTATE', 'select 1', 'PDOException', 'Illuminate', '/workspace', '/app/',
            (string) config('app.key'),
        ];

        foreach ($sekrety as $sekret) {
            if ($sekret === '') {
                continue;
            }

            $this->assertStringNotContainsString($sekret, $tresc, "Odpowiedź /health zdradza „{$sekret}\".");
        }

        $this->assertDoesNotMatchRegularExpression('/@/', $tresc, 'W odpowiedzi /health jest coś w rodzaju adresu e-mail.');
        $this->assertDoesNotMatchRegularExpression('#https?://#i', $tresc, 'W odpowiedzi /health jest adres URL.');
    }

    private function zepsujBaze(\Closure $cialo): void
    {
        $domyslna = (string) config('database.default');

        // Port 1 nikogo nie słucha — PDO odbija się natychmiast.
        config([
            'database.connections.zepsuta' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'tajna-baza-kontraktu',
                'username' => 'tajny-uzytkownik-kontraktu',
                'password' => 'tajne-haslo-kontraktu',
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
            config(['database.default' => $domyslna]);
            DB::purge('zepsuta');
        }
    }

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
}
