<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Które zmienne dostaje która rola Railwaya — issues #1013 i #1014.
 *
 * DWIE USTERKI, JEDEN MECHANIZM
 *
 *  #1014: `OPENAI_MODERATION_KEY` i `KUKING_MODEL_ALARM_EMAIL` są czytane
 *  przez `config/kuking.php`, a `.railway/railway.ts` ich nie przekazywał.
 *  Dzisiejsza produkcja ma je wpisane ręcznie w jednym serwisie; po
 *  rozdzieleniu usług (#595) worker i scheduler powstałyby z IaC bez nich —
 *  moderacja modelem wyłączyłaby się po cichu (świadomy fail-open, D-055),
 *  bez czerwonego deployu i bez `degraded` w `/health`.
 *
 *  #1013: web, worker i scheduler dostawały ten sam komplet sekretów przez
 *  jeden `...appEnv`. Worker dekodujący nieufne zdjęcia miał sekret OAuth
 *  i Turnstile, scheduler sekret OAuth, web token odczytu kopii bazy.
 *  (Klucze poczty scheduler MA mieć — digest buduje mailer w jego procesie,
 *  patrz `harmonogram_budujacy_mailer_ma_klucze_poczty`.)
 *
 * Obie są tym samym rozjazdem: między tym, co KOD czyta, a tym, co IaC
 * przekazuje KONKRETNEJ usłudze. Railway nie daje procesowi Shared Variable
 * sam z siebie — referencję tworzy blok `env` danej usługi.
 *
 * CO TEN TEST PILNUJE
 *
 *  1. Każda zmienna czytana w `config/*.php` BEZ wartości domyślnej ma źródło
 *     w `railway.ts` albo stoi na liście WYJATKI z powodem. Zmienna z wartością
 *     domyślną jest poza zakresem: wartość domyślna jest wartością produkcji.
 *  2. Zestaw referencji `ctx.shared` każdej rozdzielonej roli jest DOKŁADNIE
 *     taki, jak w MACIERZ — pilnuje naraz braków (#1014) i nadmiaru (#1013).
 *  3. Rola `all` dostaje sumę trzech ról.
 *
 * JAK CZYTA `railway.ts`
 * Statycznie, bez uruchamiania TypeScriptu: bloki `const xxxEnv = {...}`,
 * klucze `NAZWA:` i rozwinięcia `...inny`. Linie-komentarze są pomijane,
 * bo to w nich pada najwięcej nazw zmiennych („ŚWIADOMIE BEZ ..."). Parser
 * jest celowo wąski: gdy ktoś zmieni kształt pliku, test ma zapalić
 * z czytelnym powodem, a nie przepuścić pusty zbiór.
 *
 * CZEGO NIE DOWODZI: że wartości istnieją w panelu Railwaya. Tego z PHPUnita
 * nie da się sprawdzić — lista czynności dla właściciela jest w
 * `docs/infra/DEPLOYMENT_RUNBOOK.md`, KROK 8.
 */
class ZmienneRailwayaPerRolaTest extends TestCase
{
    private const RAILWAY = '.railway/railway.ts';

    /**
     * Każda zmienna, którą `railway.ts` bierze z `ctx.shared`, z listą ról,
     * które MAJĄ ją dostać, i kodem, który ją czyta. Rola spoza listy NIE MOŻE
     * jej dostać.
     *
     * @var array<string, array{role: list<string>, powod: string}>
     */
    private const MACIERZ = [
        'APP_KEY' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Szyfrowanie sesji (web), ładunków zadań (worker) i cache; `config/app.php`.',
        ],
        'APP_PREVIOUS_KEYS' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Rotacja APP_KEY (PR #1437): każda rola odszyfrowuje dane zapisane starym kluczem.',
        ],
        'AWS_ACCESS_KEY_ID' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Dyski r2/r2_publiczne/r2_eksporty: web wgrywa i podpisuje adresy, worker przetwarza '
                .'zdjęcia i buduje paczki, scheduler kasuje osierocone zdjęcia (`OsieroconeZdjecia`), '
                .'wygasłe paczki (`CleanUpDataExports`) i pliki skasowanych kont (`PurgeExpiredAccountDeletions`).',
        ],
        'AWS_SECRET_ACCESS_KEY' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Jak AWS_ACCESS_KEY_ID.',
        ],
        'AWS_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak AWS_ACCESS_KEY_ID.'],
        'AWS_PUBLIC_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak AWS_ACCESS_KEY_ID.'],
        'AWS_EXPORTS_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak AWS_ACCESS_KEY_ID.'],
        'AWS_ENDPOINT' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak AWS_ACCESS_KEY_ID.'],
        'LOG_BLAD_WEBHOOK_URL' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Kanał `blad_webhook` (`config/logging.php`): błąd może paść w każdej roli; '
                .'alarm kopii (`AlarmKopii`) wysyła scheduler.',
        ],
        'EMAILLABS_APP_KEY' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Transport poczty: web wysyła synchronicznie `WyslijOdpowiedz` i sprawdza `App\Support\Poczta` '
                .'(`/health`, formularze); worker wysyła listy z kolejki i `GenerateUserExport`; scheduler '
                .'w digeście (`kuking:wyslij-podsumowania`) woła `Mail::to()->queue()`, a `Mail::to()` buduje '
                .'transport od razu — bez klucza `BrakKonfiguracjiEmailLabs` i digest nie wychodzi.',
        ],
        'EMAILLABS_SECRET_KEY' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak EMAILLABS_APP_KEY.'],
        'EMAILLABS_SMTP_ACCOUNT' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak EMAILLABS_APP_KEY.'],
        'MAIL_HOST' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Uśpione SMTP (`config/mail.php`, mailer `smtp`) — te same role, które budują transport.',
        ],
        'MAIL_PORT' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak MAIL_HOST.'],
        'MAIL_USERNAME' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak MAIL_HOST.'],
        'MAIL_PASSWORD' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'Jak MAIL_HOST.'],
        'TURNSTILE_SITE_KEY' => [
            'role' => ['web'],
            'powod' => 'Widget na formularzach publicznych i `/health` (`App\Support\Turnstile`).',
        ],
        'TURNSTILE_SECRET_KEY' => ['role' => ['web'], 'powod' => 'Weryfikacja tokenu w żądaniu HTTP formularza.'],
        'GOOGLE_CLIENT_ID' => ['role' => ['web'], 'powod' => 'Trasy OAuth Google i `/health`.'],
        'GOOGLE_CLIENT_SECRET' => ['role' => ['web'], 'powod' => 'Wymiana kodu OAuth na trasie powrotu.'],
        'FACEBOOK_CLIENT_ID' => ['role' => ['web'], 'powod' => 'Trasy OAuth Facebooka i `/health`.'],
        'FACEBOOK_CLIENT_SECRET' => [
            'role' => ['web'],
            'powod' => 'Wymiana kodu OAuth i podpis żądania usunięcia danych od Meta — obie drogi to trasy HTTP.',
        ],
        'CLOUDFLARE_ANALYTICS_TOKEN' => [
            'role' => ['web'],
            'powod' => 'Beacon w HTML-u (`layout.blade.php`, `ApplySecurityHeaders`) i `/health`.',
        ],
        'CLOUDFLARE_ZONE_ID' => [
            'role' => ['web', 'worker'],
            'powod' => 'Worker: `PurgePublicMediaCache`. Web: `HealthController::sprawdzCzyszczenieCdn` '
                .'sprawdza obecność na produkcji. Scheduler tylko kolejkuje job.',
        ],
        'CLOUDFLARE_PURGE_TOKEN' => ['role' => ['web', 'worker'], 'powod' => 'Jak CLOUDFLARE_ZONE_ID.'],
        'OPENAI_MODERATION_KEY' => [
            'role' => ['worker'],
            'powod' => 'Job `PrzeanalizujTresc` → `KlientOpenAI` (#1014).',
        ],
        'KUKING_MODEL_ALARM_EMAIL' => [
            'role' => ['web', 'worker', 'scheduler'],
            'powod' => 'Web: `AlarmujOPilnymZgloszeniu` synchronicznie w żądaniu zgłoszenia od człowieka '
                .'(`ReportContent`, `ZglosNielegalnaTresc`, D-236). Worker: `AlarmujModeratora` '
                .'z `PrzeanalizujTresc`. Scheduler: `kuking:podsumowanie-automatu` '
                .'i `kuking:pilnuj-terminow-odwolan` (#1014).',
        ],
        'AWS_KOPIE_BUCKET' => [
            'role' => ['scheduler'],
            'powod' => 'Dysk `r2_kopie` czyta wyłącznie `kuking:sprawdz-kopie` (`StanKopiiBazy`) z harmonogramu.',
        ],
        'AWS_KOPIE_ACCESS_KEY_ID' => ['role' => ['scheduler'], 'powod' => 'Jak AWS_KOPIE_BUCKET.'],
        'AWS_KOPIE_SECRET_ACCESS_KEY' => ['role' => ['scheduler'], 'powod' => 'Jak AWS_KOPIE_BUCKET.'],
        'AWS_ZDJECIA_KOPIA_BUCKET' => [
            'role' => ['scheduler'],
            'powod' => 'Dysk `r2_kopia_zdjec` czyta wyłącznie `kuking:sprawdz-kopie-zdjec` (#1497, D-257), '
                .'uruchamiane ręcznie po migawce w konsoli schedulera — proces bez ruchu z internetu, '
                .'jak odczyt kopii bazy. Token tylko do odczytu tego bucketu.',
        ],
        'AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID' => ['role' => ['scheduler'], 'powod' => 'Jak AWS_ZDJECIA_KOPIA_BUCKET.'],
        'AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY' => ['role' => ['scheduler'], 'powod' => 'Jak AWS_ZDJECIA_KOPIA_BUCKET.'],
        'KUKING_PULS_HARMONOGRAMU_URL' => [
            'role' => ['scheduler'],
            'powod' => '`kuking:puls-harmonogramu` z harmonogramu (#599, #1659); adres zawiera token monitora.',
        ],
        'KUKING_EDGE_TOKEN' => [
            'role' => ['web'],
            'powod' => 'Bramka tokenu krawędzi Cloudflare (`TokenKrawedzi`, `NormalizeForwardedFor`) — tylko żądania HTTP.',
        ],
        'KUKING_EDGE_TOKEN_POPRZEDNI' => ['role' => ['web'], 'powod' => 'Jak KUKING_EDGE_TOKEN, na czas rotacji sekretu.'],
        'KUKING_HOST_USER_ID' => [
            'role' => ['web', 'scheduler'],
            'powod' => '`HostUserResolver` (#1089, #1375). Web: `ZalozKonto` (auto-obserwowanie przy rejestracji, '
                .'też przez Google/Facebooka) i `PublishPost` (alert pierwszego wpisu). Scheduler: '
                .'`kuking:policz-kukingow` → `LiczbaKukingow` → `CookEligibility`. UUID różny w każdym środowisku.',
        ],
    ];

    /**
     * Zmienne czytane w `config/*.php` bez wartości domyślnej, których
     * `railway.ts` ŚWIADOMIE nie przekazuje.
     *
     * @var array<string, string>
     */
    private const WYJATKI = [
        // --- Wstrzykiwane przez Railway albo ustawiane awaryjnie ręcznie ------
        'RAILWAY_GIT_COMMIT_SHA' => 'Railway wstrzykuje ją sam do każdego wdrożenia.',
        'KUKING_ZAUFANE_HOSTY' => 'Awaryjny przełącznik z panelu, gdy Railway zmieni host healthchecku (`config/proxy.php`).',
        'KUKING_WYDANO' => 'Nadpisanie znacznika wydania z `bootstrap/wydanie.txt` — dla testów, nie dla produkcji.',
        'KUKING_DEMO_HASLO' => 'Tylko `DemoSeeder`; pusto = seeder losuje hasło.',
        'KUKING_TEST_USERNAMES' => 'Opcjonalna lista kont testowych dla metryki; pusto = metryka liczy wszystkich.',
        'KUKING_POTWIERDZENIA_RODO_RETENTION_MONTHS' => 'Świadomie bez wartości: pusto = komenda odmawia kasowania, '
            .'dopóki właściciel nie ustali okresu.',
        'KUKING_R2_PUBLICZNE_ADRESY' => 'Wejście ręcznie uruchamianej bramki R2 (`docs/infra/BRAMKA_R2.md`); '
            .'adresy żyją w panelu Cloudflare.',
        'AWS_LEGACY_BUCKET' => 'Stary, jeden bucket (dysk `r2_legacy`) — ustawiany ręcznie tylko tam, gdzie '
            .'jest jeszcze w użyciu, do końca `kuking:przenies-zdjecia` (#120).',
        'AWS_URL' => 'Wycofane (audyt W7-02, D-020); zostaje tylko jako zapasowe `AWS_LEGACY_URL`.',
        'KUKING_EXPORT_TEMP_DIR' => 'Pusto = `<tmp>/kuking-eksport.u<uid>` osobny dla użytkownika systemu '
            .'(`ExportTempDirectory`, #1455); ustawiane ręcznie tylko na workerze, gdyby tmp kontenera nie wystarczył.',

        // --- Domyślne połączenie Laravela jest poprawne (null = domyślne) ----
        'CACHE_STORAGE_DISK' => 'Cache stoi na bazie (`CACHE_STORE=database`); dysk cache nieużywany.',
        'DB_CACHE_CONNECTION' => 'null = domyślne połączenie `pgsql`.',
        'DB_CACHE_LOCK_CONNECTION' => 'null = domyślne połączenie `pgsql`.',
        'DB_CACHE_LOCK_TABLE' => 'null = tabela domyślna Laravela.',
        'DB_QUEUE_CONNECTION' => 'null = domyślne połączenie `pgsql`.',
        'SESSION_CONNECTION' => 'null = domyślne połączenie `pgsql`.',
        'SESSION_STORE' => 'null = sterownik z SESSION_DRIVER.',
        'SESSION_DOMAIN' => 'null = host żądania, czyli dokładnie kuking.pl bez subdomen.',
        'DB_PASSWORD' => 'Połączenie idzie przez `DB_URL` (referencja do serwisu Postgres).',
        'DB_SOCKET' => 'Połączenie idzie przez `DB_URL`, nie przez gniazdo.',

        // --- Sterowniki, których projekt nie używa (AGENTS.md §3, D-047) ----
        'MYSQL_ATTR_SSL_CA' => 'MySQL nie jest używany (PostgreSQL).',
        'DYNAMODB_ENDPOINT' => 'Cache DynamoDB nieużywany.',
        'MEMCACHED_USERNAME' => 'Memcached nieużywany.',
        'MEMCACHED_PASSWORD' => 'Memcached nieużywany.',
        'MEMCACHED_PERSISTENT_ID' => 'Memcached nieużywany.',
        'REDIS_URL' => 'Redis nieużywany — kolejka, cache i sesje na PostgreSQL.',
        'REDIS_USERNAME' => 'Redis nieużywany.',
        'REDIS_PASSWORD' => 'Redis nieużywany.',
        'SQS_SUFFIX' => 'Kolejka SQS nieużywana.',
        'MAIL_URL' => 'Mailer `smtp` składany z MAIL_HOST/MAIL_PORT, nie z adresu.',
        'MAIL_LOG_CHANNEL' => 'Mailer `log` zakazany na produkcji (list nie wychodzi, a zgłasza sukces).',
        'MAIL_SES_KEY' => 'Mailer SES nieużywany (EmailLabs, D-047).',
        'MAIL_SES_SECRET' => 'Mailer SES nieużywany (EmailLabs, D-047).',
        'POSTMARK_API_KEY' => 'Mailer Postmark nieużywany (EmailLabs, D-047).',
        'POSTMARK_MESSAGE_STREAM_ID' => 'Mailer Postmark nieużywany.',
        'RESEND_API_KEY' => 'Mailer Resend nieużywany (EmailLabs, D-047).',
        'LOG_SLACK_WEBHOOK_URL' => 'Błędy idą kanałem `blad_webhook` (LOG_BLAD_WEBHOOK_URL), nie `slack`.',
        'PAPERTRAIL_URL' => 'Papertrail nieużywany — logi w stderr Railwaya.',
        'PAPERTRAIL_PORT' => 'Papertrail nieużywany.',
        'SLACK_BOT_USER_OAUTH_TOKEN' => 'Powiadomienia Slack nieużywane.',
        'SLACK_BOT_USER_DEFAULT_CHANNEL' => 'Powiadomienia Slack nieużywane.',
    ];

    /**
     * Sekrety przekazywane WYŁĄCZNIE na produkcji: `isProduction ? ctx.shared.X : ""`.
     * Środowisko PR jest kopią bazowego, więc bez warunku preview wysyłałby
     * treści pod produkcyjnym kluczem modelu, a alarmy do prawdziwego moderatora.
     */
    private const TYLKO_PRODUKCJA = ['OPENAI_MODERATION_KEY', 'KUKING_MODEL_ALARM_EMAIL'];

    /** Warunek „tylko produkcja” w `railway.ts`; `%s` = nazwa zmiennej. */
    private const WZOR_TYLKO_PRODUKCJA = '/^isProduction\s*\?\s*ctx\.shared\.%s\s*:\s*""$/';

    /** Role, które mają własne usługi po rozdzieleniu (#595). */
    private const ROLE_ROZDZIELONE = ['web', 'worker', 'scheduler'];

    #[Test]
    public function kazda_zmienna_bez_wartosci_domyslnej_ma_zrodlo_w_railway_albo_powod(): void
    {
        $przekazywane = [];
        foreach ($this->zmienneRol() as $zmienne) {
            $przekazywane += $zmienne;
        }

        $brakujace = [];
        foreach ($this->zmienneConfigBezDomyslnej() as $zmienna) {
            if (! isset($przekazywane[$zmienna]) && ! isset(self::WYJATKI[$zmienna])) {
                $brakujace[] = $zmienna;
            }
        }

        $this->assertSame(
            [],
            $brakujace,
            "`config/*.php` czyta te zmienne bez wartości domyślnej, a `.railway/railway.ts` nie przekazuje\n"
            .'ich żadnej roli: '.implode(', ', $brakujace).".\n"
            .'Po rozdzieleniu usług (#595) funkcja, która od nich zależy, wyłączy się po cichu (#1014). '
            .'Dopisz zmienną do zestawu roli, która ją czyta (i do MACIERZ w tym teście), albo do WYJATKI z powodem.',
        );
    }

    #[Test]
    public function lista_wyjatkow_nie_zawiera_zmiennych_martwych_ani_przekazywanych(): void
    {
        $czytane = array_flip($this->zmienneConfigBezDomyslnej());

        $przekazywane = [];
        foreach ($this->zmienneRol() as $zmienne) {
            $przekazywane += $zmienne;
        }

        foreach (self::WYJATKI as $zmienna => $powod) {
            $this->assertNotSame('', trim($powod), "Wyjątek {$zmienna} nie ma powodu.");
            $this->assertArrayHasKey(
                $zmienna,
                $czytane,
                "Wyjątek {$zmienna} nie jest już czytany w `config/*.php` bez wartości domyślnej — usuń go z WYJATKI, "
                .'żeby lista nie rosła o nazwy, które niczego nie znaczą.',
            );
            $this->assertArrayNotHasKey(
                $zmienna,
                $przekazywane,
                "{$zmienna} jest i na liście wyjątków, i w `railway.ts`. Jedno z dwojga jest nieprawdą.",
            );
        }
    }

    #[Test]
    public function kazda_rola_dostaje_dokladnie_sekrety_z_macierzy(): void
    {
        $role = $this->zmienneRol();

        foreach (self::ROLE_ROZDZIELONE as $rola) {
            $maMiec = array_keys(array_filter(
                self::MACIERZ,
                static fn (array $wpis): bool => in_array($rola, $wpis['role'], true),
            ));
            $ma = array_keys(array_filter(
                $role[$rola],
                static fn (string $wartosc): bool => str_starts_with($wartosc, 'ctx.shared.'),
            ));
            sort($maMiec);
            sort($ma);

            $brak = array_values(array_diff($maMiec, $ma));
            $nadmiar = array_values(array_diff($ma, $maMiec));

            $this->assertSame(
                [],
                $brak,
                "Rola `{$rola}` nie dostaje w `railway.ts`: ".implode(', ', $brak).".\n"
                .'Kod tej roli ich potrzebuje (powody w MACIERZ) — bez referencji funkcja wyłączy się po cichu (#1014).',
            );
            $this->assertSame(
                [],
                $nadmiar,
                "Rola `{$rola}` dostaje w `railway.ts` sekrety spoza swojej macierzy: ".implode(', ', $nadmiar).".\n"
                .'Każdy sekret w roli, która go nie czyta, to poświadczenie oddane przy przejęciu procesu (#1013). '
                .'Jeśli rola naprawdę go czyta, dopisz ją w MACIERZ z nazwą konsumenta w kodzie.',
            );
        }
    }

    /**
     * Kryteria akceptacji #1013 i #1014 wprost, z komunikatem pisanym dla
     * człowieka. Powtarzają fragment macierzy celowo: gdyby ktoś „naprawił"
     * test, dopisując workera do MACIERZ przy sekrecie OAuth, ten test i tak
     * zapali.
     */
    #[Test]
    public function kryteria_akceptacji_1013_i_1014(): void
    {
        $role = $this->zmienneRol();

        foreach (['worker', 'scheduler'] as $rola) {
            foreach (['GOOGLE_CLIENT_SECRET', 'FACEBOOK_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY'] as $sekret) {
                $this->assertArrayNotHasKey(
                    $sekret,
                    $role[$rola],
                    "`{$rola}` nie obsługuje logowania ani formularzy, a dostaje {$sekret} (#1013).",
                );
            }
        }

        foreach (['web', 'worker'] as $rola) {
            $this->assertArrayNotHasKey(
                'AWS_KOPIE_SECRET_ACCESS_KEY',
                $role[$rola],
                "`{$rola}` nie sprawdza kopii bazy — robi to `kuking:sprawdz-kopie` na schedulerze (#1013).",
            );
        }

        // Tu stało kiedyś `assertArrayNotHasKey('EMAILLABS_SECRET_KEY', scheduler)`
        // z uzasadnieniem „scheduler listy tylko kolejkuje". To było nieprawdą:
        // `Mail::to()` w digeście buduje transport — patrz
        // `harmonogram_budujacy_mailer_ma_klucze_poczty`.

        $this->assertSame(
            'ctx.shared.OPENAI_MODERATION_KEY',
            $role['worker']['OPENAI_MODERATION_KEY'] ?? null,
            'Worker wykonuje `PrzeanalizujTresc` i bez klucza moderacja modelem wyłączy się po cichu (#1014).',
        );
        $this->assertArrayNotHasKey('OPENAI_MODERATION_KEY', $role['web'], 'Web nie woła modelu (#1013).');
        $this->assertArrayNotHasKey('OPENAI_MODERATION_KEY', $role['scheduler'], 'Scheduler nie woła modelu (#1013).');

        $this->assertSame(
            'ctx.shared.KUKING_MODEL_ALARM_EMAIL',
            $role['scheduler']['KUKING_MODEL_ALARM_EMAIL'] ?? null,
            'Scheduler uruchamia podsumowanie automatu i pilnowanie terminów odwołań — bez adresu listy nie wyjdą (#1014).',
        );
        $this->assertSame(
            'ctx.shared.KUKING_MODEL_ALARM_EMAIL',
            $role['web']['KUKING_MODEL_ALARM_EMAIL'] ?? null,
            'Web woła `AlarmujOPilnymZgloszeniu` synchronicznie w żądaniu zgłoszenia od człowieka — bez adresu '
            .'pilne zgłoszenie (D-236) po cichu przestanie budzić moderatora po rozdzieleniu usług.',
        );
    }

    /**
     * Klucz modelu i adres alarmu tylko na produkcji. Staging i każde
     * środowisko PR (ani `production`, ani `staging`) dostają pusty napis —
     * dlatego warunek musi brzmieć `isProduction`, a nie `!isStaging`.
     */
    #[Test]
    public function klucz_modelu_i_adres_alarmu_tylko_na_produkcji(): void
    {
        $widziane = [];
        foreach ($this->zmienneRol(surowe: true) as $rola => $zmienne) {
            foreach (self::TYLKO_PRODUKCJA as $zmienna) {
                if (! isset($zmienne[$zmienna])) {
                    continue;
                }
                $widziane[$zmienna] = true;
                $this->assertMatchesRegularExpression(
                    sprintf(self::WZOR_TYLKO_PRODUKCJA, $zmienna),
                    $zmienne[$zmienna],
                    "Rola `{$rola}` dostaje {$zmienna} bez warunku `isProduction ? ctx.shared.{$zmienna} : \"\"`. "
                    .'Środowisko PR jest kopią bazowego — podgląd wysyłałby treści pod produkcyjnym kluczem '
                    .'albo alarmy do prawdziwego moderatora.',
                );
            }
        }

        // Kontrola niepustości: pętla nad rolami bez tych zmiennych
        // przeszłaby nad niczym.
        $this->assertEqualsCanonicalizing(
            self::TYLKO_PRODUKCJA,
            array_keys($widziane),
            'Nie znalazłem w żadnej roli którejś ze zmiennych TYLKO_PRODUKCJA — parser zgubił blok albo zmienną.',
        );
    }

    #[Test]
    public function rola_all_dostaje_sume_trzech_rol(): void
    {
        $role = $this->zmienneRol();

        foreach (self::ROLE_ROZDZIELONE as $rola) {
            $brak = array_keys(array_diff_key($role[$rola], $role['all']));

            $this->assertSame(
                [],
                $brak,
                "Rola `all` (staging, preview, dzisiejsza produkcja) robi też pracę roli `{$rola}`, "
                .'a nie dostaje: '.implode(', ', $brak).'.',
            );
        }
    }

    /**
     * `kopia-bazy` trzyma w rękach zrzut całej bazy, więc ma zamkniętą listę
     * zmiennych (#193) i nie jest żadną z ról aplikacji. Spread któregokolwiek
     * zestawu (`...appEnv`, `...schedulerEnv`, …) dałby temu procesowi APP_KEY,
     * klucze do zdjęć i poczty — macierz ról tego nie złapie, bo nie czyta
     * tego serwisu.
     */
    #[Test]
    public function serwis_kopii_nie_rozwija_zadnego_zestawu_aplikacji(): void
    {
        $env = $this->envUslugi($this->kodBezKomentarzy(), 'kopia-bazy');

        $this->assertStringNotContainsString(
            '...',
            $env,
            'Serwis `kopia-bazy` ma zamkniętą listę zmiennych (#193). Żaden zestaw aplikacji '
            .'(`appEnv`, `schedulerEnv`, …) nie może tam trafić spreadem.',
        );
        // Kontrola niepustości: parser, który zgubi blok, przepuściłby pusty napis.
        $this->assertStringContainsString('KOPIA_S3_SEKRET: ctx.shared.R2_KOPIE_SECRET_ACCESS_KEY', $env);
    }

    #[Test]
    public function kazda_zmienna_macierzy_ma_konsumenta_w_config(): void
    {
        $czytane = array_flip($this->zmienneConfig());

        foreach (self::MACIERZ as $zmienna => $wpis) {
            $this->assertArrayHasKey(
                $zmienna,
                $czytane,
                "{$zmienna} jest w macierzy, ale nie czyta jej żadne `env()` w `config/*.php`. "
                .'Sekret bez konsumenta to tylko powierzchnia ataku — usuń go z `railway.ts` (#1013).',
            );
            $this->assertNotSame([], $wpis['role'], "{$zmienna} nie ma żadnej roli.");
            $this->assertNotSame('', trim($wpis['powod']), "{$zmienna} nie ma powodu.");
        }
    }

    /**
     * Regresja z przeglądu #1013: scheduler bez kluczy poczty.
     *
     * `Schedule::call()` wykonuje komendę W PROCESIE schedulera. Komenda, która
     * woła `Mail::` (także `Mail::to()->queue()`), rozwiązuje mailer domyślny,
     * a `MailManager::resolve()` buduje transport od razu —
     * `PocztaServiceProvider::transport()` przy pustym EMAILLABS_APP_KEY rzuca
     * `BrakKonfiguracjiEmailLabs`. Digest nie wychodzi, a pierwszy odbiorca
     * traci tydzień (wiersz `weekly_digest_sends` zajęty przed wysyłką).
     *
     * Powiadomienia `ShouldQueue` przez `->notify()` transportu przy
     * kolejkowaniu nie budują, więc strażnik patrzy na `Mail::` i `notifyNow(`.
     * Czyta sam plik komendy — zależność schowana głębiej (serwis wołający
     * `Mail::`) wymaga dopisania tu wprost; lista w raporcie przeglądu.
     */
    #[Test]
    public function harmonogram_budujacy_mailer_ma_klucze_poczty(): void
    {
        $budujaMailer = $this->komendyHarmonogramuBudujaceMailer();

        // Kontrola niepustości: parser, który nie rozpozna żadnej komendy,
        // przepuściłby scheduler bez kluczy nad pustym zbiorem.
        $this->assertContains(
            'kuking:wyslij-podsumowania',
            $budujaMailer,
            'Strażnik nie widzi, że digest woła `Mail::` z procesu schedulera — parser `routes/console.php` '
            .'albo komend przestał działać.',
        );

        $scheduler = $this->zmienneRol()['scheduler'];

        foreach (['EMAILLABS_APP_KEY', 'EMAILLABS_SECRET_KEY', 'EMAILLABS_SMTP_ACCOUNT'] as $zmienna) {
            $this->assertSame(
                'ctx.shared.'.$zmienna,
                $scheduler[$zmienna] ?? null,
                'Scheduler uruchamia w swoim procesie komendy, które budują mailer ('.implode(', ', $budujaMailer).'), '
                ."a nie dostaje {$zmienna}. `PocztaServiceProvider::transport()` rzuci `BrakKonfiguracjiEmailLabs` "
                .'i list nie wyjdzie. Dodaj `...pocztaEnv` do `schedulerEnv` w `.railway/railway.ts`.',
            );
        }
    }

    /**
     * Komendy uruchamiane z `routes/console.php` przez `Artisan::call()`
     * (wszystkie w `Schedule::call()`), których plik woła `Mail::` albo
     * `notifyNow(` poza komentarzem.
     *
     * @return list<string>
     */
    private function komendyHarmonogramuBudujaceMailer(): array
    {
        $konsola = (string) file_get_contents(base_path('routes/console.php'));
        // Zadania idą przez adapter `Harmonogram::artisan()` (#835), który
        // też wykonuje komendę W PROCESIE schedulera; goły `Artisan::call()`
        // zostaje w wzorze, żeby strażnik nie oślepł, gdyby ktoś go przywrócił.
        preg_match_all("/(?:Harmonogram::artisan|Artisan::call)\('([\w:-]+)'/", $konsola, $trafienia);
        $nazwy = array_values(array_unique($trafienia[1]));
        $this->assertNotEmpty($nazwy, 'Nie znalazłem żadnego `Harmonogram::artisan()` ani `Artisan::call()` w `routes/console.php`.');

        $pliki = [];
        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $plik) {
            if (preg_match('/\$signature\s*=\s*[\'"]([\w:-]+)/', (string) file_get_contents($plik), $m) === 1) {
                $pliki[$m[1]] = $plik;
            }
        }

        $wynik = [];
        foreach ($nazwy as $nazwa) {
            $this->assertArrayHasKey($nazwa, $pliki, "Nie znalazłem klasy komendy `{$nazwa}` w `app/Console/Commands`.");

            // Komentarze wycina tokenizer PHP, nie wyrażenie na liniach:
            // `preg_split('/\R/')` bez `/u` tnie polskie „ą" (bajty C4 85,
            // a 0x85 to NEL), więc linia docblocka traciła gwiazdkę i `Mail::`
            // z komentarza liczyło się jako kod — strażnik świeciłby zawsze.
            $kod = '';
            foreach (token_get_all((string) file_get_contents($pliki[$nazwa])) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $kod .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/\bMail::|->notifyNow\(/', $kod) === 1) {
                $wynik[] = $nazwa;
            }
        }

        return $wynik;
    }

    // =========================================================================
    //  Czytanie plików
    // =========================================================================

    /**
     * Zmienne każdej roli: nazwa → źródło wartości (np. `ctx.shared.APP_KEY`).
     *
     * @return array{web: array<string, string>, worker: array<string, string>, scheduler: array<string, string>, all: array<string, string>}
     */
    private function zmienneRol(bool $surowe = false): array
    {
        $kod = $this->kodBezKomentarzy();
        $stale = $this->staleEnv($kod);

        $web = $this->envUslugi($kod, 'web');
        $this->assertSame(
            1,
            preg_match('/\.\.\.\(\s*splitServices\s*\?\s*(\w+)\s*:\s*(\w+)\s*\)/', $web, $m),
            'Blok `env` serwisu web nie ma już `...(splitServices ? webEnv : wszystkieRoleEnv)`. '
            .'Test nie umie wtedy odróżnić rozdzielonego web od roli `all` — popraw test razem z plikiem.',
        );
        $webBezWarunku = str_replace($m[0], '', $web);

        $wynik = [
            'web' => $this->rozwin('...'.$m[1].",\n".$webBezWarunku, $stale),
            'all' => $this->rozwin('...'.$m[2].",\n".$webBezWarunku, $stale),
            'worker' => $this->rozwin($this->envUslugi($kod, 'worker'), $stale),
            'scheduler' => $this->rozwin($this->envUslugi($kod, 'scheduler'), $stale),
        ];

        if (! $surowe) {
            // `isProduction ? ctx.shared.X : ""` to wciąż referencja do X —
            // warunek sprawdza osobno `klucz_modelu_i_adres_alarmu_tylko_na_produkcji`.
            foreach ($wynik as $rola => $zmienne) {
                $wynik[$rola] = array_map(
                    static fn (string $wartosc): string => (string) preg_replace(
                        '/^isProduction\s*\?\s*(ctx\.shared\.\w+)\s*:\s*""$/',
                        '$1',
                        $wartosc,
                    ),
                    $zmienne,
                );
            }
        }

        foreach ($wynik as $rola => $zmienne) {
            // Kontrola niepustości: parser, który zgubi blok, zwróciłby pusty
            // zbiór i każda asercja „czego NIE MA" przeszłaby nad niczym.
            $this->assertArrayHasKey('APP_KEY', $zmienne, "Nie odczytałem zmiennych roli `{$rola}` z railway.ts.");
            $this->assertArrayHasKey('APP_ROLE', $zmienne, "Nie odczytałem zmiennych roli `{$rola}` z railway.ts.");
        }

        return $wynik;
    }

    /** @return array<string, string> ciało każdego `const xxx = { ... };` */
    private function staleEnv(string $kod): array
    {
        preg_match_all('/const (\w+) = \{/', $kod, $trafienia, PREG_OFFSET_CAPTURE);

        $stale = [];
        foreach ($trafienia[1] as $i => [$nazwa]) {
            $start = $trafienia[0][$i][1] + strlen($trafienia[0][$i][0]) - 1;
            $stale[$nazwa] = $this->zawartoscNawiasu($kod, $start);
        }

        $this->assertArrayHasKey('appEnv', $stale, 'Nie znalazłem `const appEnv = {` w railway.ts.');

        return $stale;
    }

    private function envUslugi(string $kod, string $nazwa): string
    {
        // Serwis WWW nazywa się jak serwis produkcji (`kuking.pl`), więc w railway.ts
        // stoi jako `service(NAZWA_SERWISU_WWW, …)`, nie `service("web", …)`.
        $igla = $nazwa === 'web' ? 'service(NAZWA_SERWISU_WWW' : 'service("'.$nazwa.'"';
        $poczatek = strpos($kod, $igla);
        $this->assertNotFalse($poczatek, "Brak deklaracji serwisu `{$nazwa}` w railway.ts (szukano `{$igla}`).");

        $nastepny = strpos($kod, 'service(', $poczatek + 1);
        $blok = substr($kod, $poczatek, $nastepny === false ? null : $nastepny - $poczatek);

        $this->assertSame(
            1,
            preg_match('/^    env: \{/m', $blok, $m, PREG_OFFSET_CAPTURE),
            "Serwis `{$nazwa}` nie ma bloku `env: {` na poziomie usługi.",
        );

        return $this->zawartoscNawiasu($blok, $m[0][1] + strlen($m[0][0]) - 1);
    }

    /**
     * @param  array<string, string>  $stale
     * @param  list<string>  $stos
     * @return array<string, string>
     */
    private function rozwin(string $cialo, array $stale, array $stos = []): array
    {
        $wynik = [];

        preg_match_all('/\.\.\.(\w+)/', $cialo, $rozwiniecia);
        foreach ($rozwiniecia[1] as $nazwa) {
            $this->assertArrayHasKey($nazwa, $stale, "Rozwinięcie `...{$nazwa}` wskazuje na nieznany zestaw.");
            $this->assertNotContains($nazwa, $stos, "Cykl rozwinięć przy `{$nazwa}`.");
            $wynik = array_merge($wynik, $this->rozwin($stale[$nazwa], $stale, [...$stos, $nazwa]));
        }

        // Klucz na początku linii albo po przecinku (`{ ...workerEnv, APP_ROLE: "worker" }`).
        // Wartość do przecinka albo końca linii — wystarcza, bo sprawdzamy
        // tylko, czy pochodzi z `ctx.shared`.
        preg_match_all('/(?:^|,)\s*([A-Z][A-Z0-9_]*)\s*:\s*([^,\n]*)/m', $cialo, $klucze, PREG_SET_ORDER);
        foreach ($klucze as [, $klucz, $wartosc]) {
            $wynik[$klucz] = trim($wartosc);
        }

        return $wynik;
    }

    private function zawartoscNawiasu(string $tekst, int $otwarcie): string
    {
        $this->assertSame('{', $tekst[$otwarcie]);

        $glebokosc = 0;
        for ($i = $otwarcie, $n = strlen($tekst); $i < $n; $i++) {
            if ($tekst[$i] === '{') {
                $glebokosc++;
            } elseif ($tekst[$i] === '}') {
                $glebokosc--;
                if ($glebokosc === 0) {
                    return substr($tekst, $otwarcie + 1, $i - $otwarcie - 1);
                }
            }
        }

        $this->fail('Niezamknięty nawias klamrowy w railway.ts.');
    }

    private function kodBezKomentarzy(): string
    {
        $sciezka = base_path(self::RAILWAY);
        $this->assertFileExists($sciezka);

        $linie = preg_split('/\R/u', (string) file_get_contents($sciezka)) ?: [];
        // Komentarz po przecinku kończącym wpis (`APP_KEY: ctx.shared.APP_KEY, // ...`).
        $linie = array_map(static fn (string $linia): string => (string) preg_replace('#,\s*//.*$#', ',', $linia), $linie);

        return implode("\n", array_filter(
            $linie,
            static fn (string $linia): bool => ! str_starts_with(ltrim($linia), '//')
                && ! str_starts_with(ltrim($linia), '*')
                && ! str_starts_with(ltrim($linia), '/*'),
        ));
    }

    /** @return list<string> */
    private function zmienneConfig(): array
    {
        preg_match_all('/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $this->config(), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * `env('X')`, `env('X', null)` i `env('X', '')` — brak wartości, z którą
     * produkcja mogłaby pracować.
     *
     * @return list<string>
     */
    private function zmienneConfigBezDomyslnej(): array
    {
        preg_match_all(
            '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*(?:\)|,\s*(?:null|\'\'|"")\s*\))/',
            $this->config(),
            $m,
        );

        $wynik = array_values(array_unique($m[1]));
        $this->assertContains('APP_KEY', $wynik, 'Czytnik `config/*.php` nie widzi nawet APP_KEY — popraw wyrażenie.');

        return $wynik;
    }

    private function config(): string
    {
        $pliki = glob(base_path('config/*.php')) ?: [];
        $this->assertNotEmpty($pliki);

        return implode("\n", array_map(static fn (string $plik): string => (string) file_get_contents($plik), $pliki));
    }
}
