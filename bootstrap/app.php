<?php

declare(strict_types=1);

use App\Domain\Analytics\ZapiszSygnal;
use App\Exceptions\OdzyskanyFormularz;
use App\Http\Middleware\AktualizujOstatniaWizyte;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureModeratorHasTwoFactor;
use App\Http\Middleware\EnsureUserIsModerator;
use App\Http\Middleware\NormalizeForwardedFor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Railway healthcheck celuje w /health (App\Http\Controllers\HealthController),
        // który sprawdza bazę. Frameworkowy /up zostaje jako najprostszy sygnał
        // "proces żyje" — przydaje się, gdy baza jest w trakcie restartu.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // PIERWSZY W CAŁYM STOSIE GLOBALNYM, przed `TrustProxies` — i to jest
        // cała treść ustalenia W7-01 / SEC-01. `NormalizeForwardedFor` zostawia
        // w `X-Forwarded-For` DOKŁADNIE JEDEN wpis: ten, który dopisała nasza
        // infrastruktura, wyliczony jako n-ty OD KOŃCA łańcucha. Wszystko, co
        // klient dopisał sobie z lewej strony, znika, zanim Symfony zacznie
        // liczyć `$request->ip()`.
        //
        // Dlaczego liczba przeskoków, a nie lista adresów IP, i co się stanie,
        // gdy Railway zmieni topologię — pełne uzasadnienie stoi w komentarzu
        // tej klasy oraz w `config/proxy.php`. W skrócie: adres brzegu Railway
        // nie jest stały, a zakresy Cloudflare i tak nigdy nie są bezpośrednim
        // peerem TCP tego kontenera, więc żadna lista adresów nie ma prawa
        // zadziałać.
        $middleware->prepend(NormalizeForwardedFor::class);

        // Aplikacja NIGDY nie jest odpytywana bezpośrednio: ruch idzie przez
        // Cloudflare, a potem przez brzeg Railway. Bez tej linii Laravel nie
        // ufa żadnemu proxy i ignoruje nagłówki `X-Forwarded-*`, co psuje
        // dwie rzeczy naraz, i to po cichu:
        //
        //   1. `X-Forwarded-Proto: https` przepada, więc `$request->secure()`
        //      jest false, a `url()` generuje adresy po `http://`. Cloudflare
        //      przekierowuje je z powrotem na https i robi się pętla.
        //   2. `$request->ip()` zwraca adres brzegu Railway — ten sam dla
        //      wszystkich. Limity z `config/kuking.php` liczyłyby się wtedy
        //      wspólnie dla całego serwisu: jedna osoba wyczerpuje limit
        //      rejestracji i blokuje wszystkich pozostałych.
        //
        // `at: '*'` znaczy „ufaj tej maszynie, która się właśnie połączyła"
        // (Laravel tłumaczy `'*'` na `setTrustedProxies([REMOTE_ADDR], ...)`).
        // Jest tu bezpieczne, bo kontener nie ma publicznego adresu — dojść
        // do niego można wyłącznie przez brzeg platformy. Lista konkretnych
        // adresów IP nie wchodzi w grę: Railway ich nie gwarantuje, a zakresy
        // Cloudflare nigdy nie są tu peerem TCP, więc nie trafiłoby w nie
        // żadne żądanie.
        //
        // ZESTAW NAGŁÓWKÓW WYPISANY JAWNIE, zamiast domyślnego z frameworka.
        // Domyślny dokłada jeszcze `X-Forwarded-Prefix` (doklejany do KAŻDEGO
        // adresu generowanego przez `url()`) i zbiorczy `X-Forwarded-AWS-ELB`
        // — czyli obietnicę dla platformy, na której nie stoimy. Nic w naszym
        // łańcuchu ich nie wysyła, a każdy zaufany nagłówek to jedna rzecz
        // więcej, którą klient może podstawić. Zostają cztery, których
        // NAPRAWDĘ używamy.
        //
        // `X-Forwarded-Host` ZOSTAJE, ale świadomie i z zastrzeżeniem: jest
        // podrabialny tak samo jak reszta, a wpływa na host w adresach z
        // `url()`. Właściwym zamknięciem tego jest middleware `TrustHosts`
        // z listą hostów, a ta MUSI zawierać `healthcheck.railway.app`
        // (inaczej deploy pada na 400 — patrz `.railway/railway.ts`). To jest
        // osobna zmiana i osobne ryzyko wdrożeniowe, więc nie robi jej ta
        // łatka.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            ApplySecurityHeaders::class,

            // GLOBALNIE, nie wybiórczo na kontrolerach (issue #39).
            // Wybiórczo znaczy: następny nowy kontroler o tym zapomni, a brak
            // tej kontroli niczego nie wywala — po prostu przepuszcza konto,
            // które miało być odcięte. Middleware sam sprawdza, czy ktoś jest
            // zalogowany, więc na trasach gościa nie robi nic.
            EnsureAccountIsActive::class,

            // PO `EnsureAccountIsActive`, CELOWO (issue #114/#115, bramka V1
            // z `docs/ROADMAP.md`). Konto właśnie wylogowane przez middleware
            // wyżej (zbanowane/`pending_delete`/`erased`) nie ma tu już
            // `$request->user()`, więc nie zostaje policzone jako „ktoś tu
            // był" — ta sama granica, której już pilnuje
            // `CookEligibility` przy WAC. Sam zapis jest throttlowany
            // i nigdy nie rzuca wyjątku dalej — pełne uzasadnienie w
            // `App\Domain\Analytics\ZanotujOstatniaWizyte`.
            AktualizujOstatniaWizyte::class,
        ]);

        $middleware->alias([
            'moderator' => EnsureUserIsModerator::class,
            // Zawsze DRUGI w trasie, po 'moderator' — issue #12, patrz
            // komentarz klasy: zakłada, że użytkownik jest już moderatorem.
            'moderator.2fa' => EnsureModeratorHasTwoFactor::class,
        ]);

        // JEDYNY adres wyjęty spod ochrony CSRF i jedyny, który ma prawo nim
        // zostać. (Wcześniej stał tu komentarz obiecujący, że po wygaśnięciu
        // sesji człowiek wraca do formularza z wpisanymi danymi — kod nigdy
        // tego nie robił, a `except:` nie ma z tym nic wspólnego. Obsługa
        // wygasłej sesji jest teraz niżej, przy `TokenMismatchException`,
        // issue #81.)
        //
        // Zgłoszenia naruszeń CSP wysyła SAMA PRZEGLĄDARKA: bez sesji,
        // bez tokenu, często z innego kontekstu niż strona. Token CSRF jest
        // tu niemożliwy do podania, a nie „pominięty dla wygody".
        // Endpoint niczego nie zapisuje do bazy i zawsze zwraca 204 —
        // patrz CspReportController.
        $middleware->validateCsrfTokens(except: ['_csp']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ------------------------------------------------------------------
        //  WYGASŁA SESJA NIE ZJADA WPISANEGO TEKSTU (issue #81)
        //
        //  Scenariusz, który u osoby po sześćdziesiątce jest normą, a nie
        //  wyjątkiem: zaczęła pisać przepis, odeszła do garnka, wróciła po
        //  godzinie, kliknęła „Opublikuj". Do tej pory dostawała angielskie
        //  „Page Expired" i pusty formularz — czyli utratę półgodzinnej pracy
        //  wraz z komunikatem w obcym języku.
        //
        //  AGENTS.md §5 mówi wprost: poprawnie wpisane dane nigdy nie znikają.
        //
        //  Zamiast przekierowania oddajemy TEN SAM formularz, wypełniony
        //  treścią z odbitego żądania i ze świeżym tokenem CSRF. Ochrona CSRF
        //  zostaje w całości — ponowne wysłanie idzie przez nią normalnie,
        //  a żądanie bez tokenu dalej kończy się na 419.
        //
        //  Dlaczego nie `back()->withInput()` i czego jeszcze nie wybraliśmy:
        //  App\Exceptions\OdzyskanyFormularz.
        // ------------------------------------------------------------------
        //  UWAGA na typ w sygnaturze: to NIE jest `TokenMismatchException`.
        //  Laravel zamienia go na `HttpException(419)` w `prepareException()`,
        //  a dopiero potem woła te wywołania zwrotne — funkcja przyjmująca
        //  `TokenMismatchException` nigdy by się nie uruchomiła i cicho nic
        //  by nie robiła. Oryginalny wyjątek zostaje jako `getPrevious()`
        //  i po nim właśnie rozpoznajemy wygasłą sesję.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            // Klienci oczekujący JSON-a (fetch z Livewire'a, przyszłe API)
            // dostają to, co dostawali — `null` oddaje sprawę Laravelowi.
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return response()->view('errors.419', [
                'formularz' => OdzyskanyFormularz::zZadania($request),
            ], 419);
        });

        // ------------------------------------------------------------------
        //  429 ZOSTAWIA ŚLAD, KTÓRA TRASA GO WYWOŁAŁA
        //
        //  `ThrottleRequestsException` dziedziczy po `HttpException`, a tej
        //  Laravel z zasady nie raportuje — więc do tej pory limit zapytań
        //  nie zostawiał w logu ANI JEDNEJ linijki. Człowiek widział „Za dużo
        //  prób", a po naszej stronie nie było jak ustalić, czy chodziło
        //  o publikację wpisu, o komentarz, czy o coś zupełnie innego.
        //  Dokładnie tak wyglądało zgłoszenie właściciela: 429 przy pierwszej
        //  próbie dodania zdjęcia danego dnia, bez żadnego sposobu, żeby
        //  sprawdzić, który limit zadziałał.
        //
        //  Zwracamy `null`, więc renderowanie idzie dalej normalnie —
        //  to jest wyłącznie zapis do logu, nie zmiana zachowania.
        //
        //  CZEGO TU NIE MA, ŚWIADOMIE: adresu IP, identyfikatora konta ani
        //  ścieżki z prawdziwym slugiem (AGENTS.md §7 — żadnych PII w logach).
        //  Zapisujemy WZORZEC trasy i samą informację, czy limit liczył się
        //  po zalogowanym koncie, czy po adresie. Do ustalenia „który limit
        //  i jak długo trwa" to wystarcza, a do zidentyfikowania osoby nie.
        //
        //  DRUGI ZAPIS OBOK LOGU: `photo_upload_failed` (audyt zewnętrzny,
        //  punkt N05). Żądanie ze zdjęciem odrzucone TU nigdy nie dociera do
        //  kontrolera — `StoreUploadedImage` i `ObslugiwaneZdjecie` (walidacja
        //  formularza) o nim nie wiedzą, więc bez tego wpisu ta droga była
        //  równie niewidoczna z Postgresa jak wcześniej zła walidacja.
        //
        //  WARUNEK PO PLIKU, NIE PO NAZWIE TRASY: throttle na `posts.store`
        //  (limiter `post`) obsługuje TAKŻE wpisy czysto tekstowe — 429 przy
        //  publikowaniu samego tekstu nie ma nic wspólnego ze zdjęciem i nie
        //  powinien zaśmiecać tego sygnału. `hasFile()`/`file()` czytają
        //  `$_FILES`, które PHP wypełnia PRZED middleware'ami Laravela, więc
        //  ten odczyt jest wiarygodny nawet na etapie throttle, zanim
        //  jakakolwiek walidacja czy kontroler się uruchomiły. Sprawdzenie po
        //  nazwie pola, nie po nazwie trasy, bo automatycznie obejmuje każdy
        //  dzisiejszy i przyszły formularz przyjmujący zdjęcie — bez listy
        //  tras do pamiętania osobno (ten sam powód co `LimityZdjec`).
        //
        //  BEZ WŁASNOŚCI DODATKOWYCH: throttle działa przed jakąkolwiek
        //  walidacją, więc na tym etapie nie wiadomo jeszcze, czy plik w ogóle
        //  dałoby się odczytać — jedyne, co wiadomo NA PEWNO, to sam powód.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            Log::info('Limit zapytań zadziałał', [
                'trasa' => $request->route()?->getName() ?? '(bez nazwy)',
                'wzorzec' => $request->method().' /'.($request->route()?->uri() ?? '?'),
                'liczony_po' => $request->user() !== null ? 'koncie' : 'adresie',
                'ponow_za_s' => $e->getHeaders()['Retry-After'] ?? null,
            ]);

            $mialoZdjecie = ! empty($request->file('photos', []))
                || $request->hasFile('avatar')
                || $request->hasFile('hero_photo')
                || $request->hasFile('source_scan');

            if ($mialoZdjecie) {
                app(ZapiszSygnal::class)->handle($request->user(), ZapiszSygnal::PHOTO_UPLOAD_FAILED, [
                    'reason' => ZapiszSygnal::REASON_RATE_LIMITED,
                ]);
            }

            return null;
        });

        // ------------------------------------------------------------------
        //  BŁĄD 500 DZWONI NA TELEFON WŁAŚCICIELA, ZANIM ZGŁOSI GO UŻYTKOWNIK
        //
        //  Dziś, gdy stronie wywali się 500, NIKT się o tym nie dowiaduje —
        //  `docs/ROADMAP.md` §0 nazywa monitoring błędów fundamentem, nie
        //  ulepszeniem. Docelowe rozwiązanie to Sentry (patrz
        //  `docs/infra/MONITORING_BLEDOW.md`), ale w tym środowisku pracy
        //  `composer install` odbija się od proxy na paczkach z GitHuba, więc
        //  `composer.lock` nie da się dziś uczciwie zaktualizować. Kanał
        //  `blad_webhook` (`config/logging.php`) nie dokłada ŻADNEJ zależności
        //  Composera — Monolog i klient HTTP są już częścią Laravela.
        //
        //  DLACZEGO TU, A NIE TYLKO DOPISANE DO `LOG_STACK`
        //  Na produkcji `LOG_CHANNEL` to `stderr`, nie `stack`
        //  (`.railway/railway.ts`) — dopisanie kanału do stosu kanału
        //  domyślnego nic by więc nie zmieniło na żywej produkcji.
        //  `$exceptions->report()` woła kanał JAWNIE, niezależnie od tego,
        //  który kanał jest akurat domyślny.
        //
        //  DLACZEGO WARUNEK TUTAJ, SKORO `WebhookBleduHandler` I TAK NIC NIE
        //  WYŚLE BEZ ADRESU. Bez niego KAŻDY raportowany wyjątek budowałby
        //  kanał (`Log::channel('blad_webhook')`) tylko po to, żeby handler
        //  i tak nic nie zrobił. Tani koszt, ale zerowy jest tańszy —
        //  a wymóg brzmi wprost: „gdy zmiennej nie ma, nic się nie dzieje".
        //
        //  POZIOM „error i wyżej" BEZ DODATKOWEGO WARUNKU TUTAJ: Laravel
        //  i tak nie woła `report()` dla wyjątków z listy `dontReport`
        //  (patrz komentarz przy `ThrottleRequestsException` wyżej —
        //  `HttpException` obejmuje 4xx/404/419/429). To, co dociera do tego
        //  miejsca, jest z definicji „coś, co nie powinno się było zdarzyć".
        //  Sam kanał ma DODATKOWO `'level' => 'error'` w configu jako drugą
        //  linię obrony, gdyby kiedyś ktoś zaczął pisać na niego z innego
        //  miejsca.
        //
        //  ZERO PII W TREŚCI (AGENTS.md §7 — webhook idzie do ZEWNĘTRZNEJ
        //  usługi, nad którą nie mamy kontroli). `WebhookBleduHandler` buduje
        //  wiadomość WYŁĄCZNIE z klasy wyjątku, komunikatu, pliku:linii
        //  i wzorca trasy — nigdy z `$request->all()`, sesji, ciasteczek,
        //  adresu IP ani identyfikatora użytkownika. Pełne uzasadnienie:
        //  komentarz klasy `App\Logging\WebhookBleduHandler`.
        $exceptions->report(function (Throwable $e) {
            if (blank(config('logging.channels.blad_webhook.url'))) {
                return;
            }

            Log::channel('blad_webhook')->error($e->getMessage(), ['exception' => $e]);
        });

        // Wygaśnięcie sesji to zdarzenie normalne, nie awaria. Zgłaszanie go
        // zasypywałoby log (i webhook błędów) szumem, w którym utonęłyby
        // prawdziwe błędy — a przy okazji jest to jedyny wyjątek, któremu
        // towarzyszy treść wpisana przez człowieka. Do logów nie ma ona po co
        // trafiać.
        $exceptions->dontReport(TokenMismatchException::class);
    })->create();
