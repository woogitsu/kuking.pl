<?php

declare(strict_types=1);

use App\Logging\EmailBleduLogger;
use App\Logging\WebhookBleduLogger;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "monthly", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Powiadomienie o błędzie na Slacku/Discordzie (bez pakietu monitoringu)
        |----------------------------------------------------------------------
        |
        | Dziś, gdy stronie wywali się 500, nikt się o tym nie dowiaduje —
        | `docs/ROADMAP.md` §0 nazywa monitoring błędów fundamentem, a Sentry
        | (docelowy wybór — `docs/infra/MONITORING_BLEDOW.md`) w tym środowisku
        | pracy nie da się dziś zainstalować: `composer install` odbija się od
        | proxy na paczkach z GitHuba, więc `composer.lock` nie da się uczciwie
        | zaktualizować. Ten kanał NIE DOKŁADA żadnej zależności Composera —
        | Monolog i klient HTTP Laravela są już częścią frameworka.
        |
        | JEDNA ZMIENNA WŁĄCZA WSZYSTKO: `LOG_BLAD_WEBHOOK_URL`. Pusta/brakująca
        | (domyślny stan lokalnie, w CI i w testach) = kanał jest CAŁKOWICIE
        | martwy — `WebhookBleduHandler` nie wysyła nic i niczego nie rzuca,
        | patrz jego komentarz klasy oraz `bootstrap/app.php`, gdzie kanał jest
        | jawnie wołany z `$exceptions->report()`. Adres bierzemy z Discorda
        | (końcówka „Slack-Compatible Webhook") albo z prawdziwego Slacka —
        | oba przyjmują to samo `{"text": "..."}`.
        |
        | DLACZEGO WŁASNY STEROWNIK `custom`, A NIE WBUDOWANY `slack`
        | `Monolog\Handler\SlackWebhookHandler` łączy się przez `curl_init()`
        | z pominięciem klienta HTTP Laravela (nie da się tego przechwycić
        | `Http::fake()` w testach) i domyślnie dokleja do wiadomości CAŁY
        | kontekst rekordu logu — czyli m.in. obiekt wyjątku z argumentami
        | wywołań ze stosu. AGENTS.md §7 zakazuje PII w logach, a to jest
        | jedyny log w serwisie, który wychodzi do ZEWNĘTRZNEJ usługi — więc
        | to jest najgorsze możliwe miejsce na „chyba nic tam nie ma".
        | `WebhookBleduHandler` buduje treść SAM, z jawnie wybranych pól
        | wyjątku (klasa, komunikat, plik:linia, wzorzec trasy, ślad BEZ
        | argumentów) — nic „przy okazji" nie przejdzie. Pełne uzasadnienie:
        | komentarz klasy `App\Logging\WebhookBleduHandler`.
        |
        | POZIOM NA SZTYWNO `error` (wymóg: „nikt nie chce powiadomienia
        | o każdym info"). To NIE jest gałąź do podniesienia przez `LOG_LEVEL`
        | — ten kanał ma jeden cel i nie powinien dziedziczyć ogólnego progu
        | logowania aplikacji.
        |
        */

        'blad_webhook' => [
            'driver' => 'custom',
            'via' => WebhookBleduLogger::class,
            'url' => env('LOG_BLAD_WEBHOOK_URL'),
            'level' => 'error',
        ],

        /*
        |----------------------------------------------------------------------
        | DRUGI KANAŁ ALARMOWY: POCZTA (issue #599)
        |----------------------------------------------------------------------
        |
        | RÓWNOLEGŁY do `blad_webhook`, nie zamiast niego. Oba mogą działać
        | naraz, żaden nie wie o drugim, każdy włącza się SWOJĄ zmienną
        | i każdy przy pustej zmiennej jest całkowicie martwy.
        |
        | PO CO, SKORO JEST WEBHOOK: bo webhooka na produkcji NIE MA.
        | `LOG_BLAD_WEBHOOK_URL` nie jest tam ustawione (odczyt panelu
        | 21.09.2026), a pusty adres = cisza. Czyli dziś błąd 500, awaria
        | bazy, niedziałająca poczta, `/health degraded` i nieudane zadania
        | w kolejce są policzone, zalogowane i NIE BUDZĄ NIKOGO. Właściciel
        | nie używa Discorda ani Slacka; poczta (EmailLabs, `MAIL_MAILER=
        | emaillabs`) jest skonfigurowana i realnie działa.
        |
        | `adres`, NIE `url` — inna nazwa klucza jest tu celowa: pomyłka
        | „wkleiłem webhooka do zmiennej pocztowej" ma paść na etapie
        | konfiguracji, a nie wyjść dopiero przy pierwszej awarii.
        |
        | TA SAMA TREŚĆ, CO NA WEBHOOKU, budowana tą samą metodą
        | (`App\Logging\TrescAlarmu`) — to jest warunek bezpieczeństwa,
        | nie oszczędność kodu. Drugie formatowanie rozjechałoby się
        | z pierwszym i zaczęłoby wysyłać komunikat `QueryException`
        | z e-mailem i hashem hasła w środku (audyt A6-01).
        |
        | Pętla zwrotna (alarm o awarii poczty, wysyłany pocztą), zalew
        | skrzynki, brak kolejki oraz wybór nadawcy i odbiorcy: cztery
        | ponumerowane akapity w komentarzu klasy `EmailBleduHandler`.
        |
        | POZIOM NA SZTYWNO `error`, tak samo jak przy webhooku.
        */
        'blad_email' => [
            'driver' => 'custom',
            'via' => EmailBleduLogger::class,
            'adres' => env('LOG_BLAD_EMAIL'),
            'level' => 'error',
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        /*
        |----------------------------------------------------------------------
        | Szereg czasowy czujek — issue #598, #599
        |----------------------------------------------------------------------
        |
        | POZIOM NA SZTYWNO `info`, dokładnie z tego samego powodu, dla którego
        | `blad_webhook` ma na sztywno `error`: ten kanał ma jeden cel i nie
        | może dziedziczyć ogólnego progu logowania aplikacji.
        |
        | SKĄD SIĘ WZIĄŁ. Czujki `kuking:budzet-polaczen` i `kuking:sprawdz-kolejke`
        | pisały pomiar przez zwykłe `Log::info()`, czyli kanałem `stderr`,
        | którego poziom bierze się z `LOG_LEVEL`. A `.railway/railway.ts`
        | ustawia `LOG_LEVEL: isProduction ? "warning" : "debug"` — na produkcji
        | więc `warning`. `info` jest NIŻEJ i był odrzucany, zanim cokolwiek
        | dotarło do strumienia.
        |
        | Zmierzone 19.09.2026: harmonogram produkcji uruchomił czujkę o 11:25:02
        | i 12:25:11 UTC, oba przebiegi zameldowały „DONE" (15,15 ms i 13,65 ms),
        | a w dzienniku Railway nie ma ANI JEDNEJ linii z liczbami — przy
        | obecnych w tej samej sekundzie innych wpisach poziomu `info`. Kod
        | zapisujący pomiar był wdrożony (commit 7c300ccc jest przodkiem obu
        | wdrożeń). Definicji gotowości #598 („znany peak active connections")
        | nie dało się więc spełnić MIMO w pełni działającej czujki.
        |
        | DLACZEGO NIE OBNIŻENIE `LOG_LEVEL` NA PRODUKCJI. Bo to wpuściłoby do
        | dziennika KAŻDE `info` w serwisie, żeby przepchnąć dwie linie na
        | godzinę. Osobny kanał kosztuje mniej i nie zmienia niczego poza tymi
        | dwiema liniami. Nie jest to też ustawienie „do zmiany w panelu":
        | wartość pochodzi z manifestu wdrożenia w repozytorium, więc zmiana
        | w panelu i tak rozjechałaby się z `.railway/railway.ts`.
        |
        | Poziomu tego kanału NIE WOLNO podnieść do `warning`: zdrowy pomiar
        | nie jest ostrzeżeniem, a od alarmowania jest `AlarmPolaczen`
        | i `AlarmKolejki` na kanale `blad_webhook`.
        |
        */

        'pomiary' => [
            'driver' => 'monolog',
            'level' => 'info',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
