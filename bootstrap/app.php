<?php

declare(strict_types=1);

use App\Domain\Analytics\ZapiszSygnal;
use App\Exceptions\OdzyskanyFormularz;
use App\Http\Controllers\WydanieController;
use App\Http\Middleware\AktualizujOstatniaWizyte;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\CorrelateRequest;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureModeratorHasTwoFactor;
use App\Http\Middleware\EnsureUserIsModerator;
use App\Http\Middleware\NormalizeForwardedFor;
use App\Http\Middleware\PreventRequestForgeryExceptMediaCookie;
use App\Http\Middleware\PreventSharedSessionCache;
use App\Http\Middleware\StartSessionExceptAnonymousMedia;
use App\Logging\QueueCorrelation;
use App\Support\ZaufaneHosty;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Railway healthcheck celuje w /health (App\Http\Controllers\HealthController),
        // który sprawdza bazę. Frameworkowy /up zostaje jako najprostszy sygnał
        // "proces żyje" — przydaje się, gdy baza jest w trakcie restartu.
        health: '/up',
        // `/wydanie` (#1012) — POZA grupą `web`, jak `/up`. Sonda testu
        // dymnego pyta co kilka sekund, a w grupie `web` każde pytanie
        // zakładało nową sesję i odsyłało `Set-Cookie` z sesją i tokenem
        // CSRF. Punkt jest tylko GET-em i niczego od klienta nie przyjmuje.
        // Nagłówki bezpieczeństwa i zakaz cache daje stos globalny.
        then: function (): void {
            Route::get('/wydanie', WydanieController::class)->name('wydanie');
        },
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
        // DRUGI W STOSIE GLOBALNYM STOI `ApplySecurityHeaders` — i to NIE JEST
        // przestawienie ustalenia wyżej. `NormalizeForwardedFor` zostaje
        // PIERWSZY; nagłówki bezpieczeństwa wchodzą zaraz za nim, czyli i tak
        // po normalizacji `X-Forwarded-For`, a przed `ValidatePostSize`
        // i `PreventRequestsDuringMaintenance`.
        //
        // PO CO, SKORO TA SAMA KLASA STOI JUŻ W GRUPIE `web`. Bo połowa
        // ekranów błędu NIGDY DO TEJ GRUPY NIE DOCHODZI, a to właśnie one
        // wyświetlają najwięcej cudzej treści. Zmierzone 20 września 2026
        // (`php artisan serve`, APP_DEBUG=false) — ani jednego nagłówka
        // bezpieczeństwa, w tym ani jednej dyrektywy CSP:
        //
        //   404  wyjątek leci z ROUTERA, zanim ruszy grupa `web`;
        //   419  `ValidateCsrfToken` stoi w grupie PRZED tą klasą;
        //   429  `ThrottleRequests` jest na liście priorytetów frameworka
        //        (`Kernel::$middlewarePriority`), więc sortowanie wynosi go
        //        przed wszystko, co do tej listy nie należy — w tym przed
        //        tę klasę;
        //   413  `ValidatePostSize` jest globalny;
        //   503  `PreventRequestsDuringMaintenance` jest globalny.
        //
        // 419 i 429 to JEDYNE DWA EKRANY W SERWISIE, KTÓRE WYPISUJĄ Z POWROTEM
        // TEKST WPISANY PRZEZ CZŁOWIEKA (`OdzyskanyFormularz`). Strona, która
        // wstawia cudzą treść do HTML-a, była dokładnie tą, która szła bez
        // `script-src`, bez `frame-ancestors` i bez `X-Frame-Options`.
        //
        // DLACZEGO DWA RAZY, A NIE „przenieść z grupy `web` tutaj". Bo nonce
        // musi powstać PRZED renderowaniem widoku, a wpis w grupie `web` jest
        // tym miejscem, w którym cały serwis go dziś dostaje. Wywołanie
        // zewnętrzne nie nadpisuje niczego: `handle()` zwraca odpowiedź bez
        // zmian, gdy nagłówek `Content-Security-Policy` już na niej jest,
        // a podpis bierze z `Vite::cspNonce()`, więc obie warstwy używają
        // TEGO SAMEGO ciągu. Na zwykłej stronie ta warstwa nie robi nic.
        // TRZECI W STOSIE GLOBALNYM STOI `PreventSharedSessionCache` (#597).
        //
        // NA WEJŚCIU pozycja nie ma znaczenia — ta klasa nie czyta ani nie
        // zmienia żądania. Liczy się WYJŚCIE: w potoku Laravela middleware,
        // który wchodzi jako n-ty, dotyka odpowiedzi jako n-ty OD KOŃCA.
        // Stąd „trzeci od góry" znaczy „trzeci od końca na odpowiedzi", czyli
        // PO grupie `web`, PO routerze i PO module wyjątków. Widzi więc:
        //
        //   – `Set-Cookie` dopisane przez `StartSession`
        //     i `AddQueuedCookiesToResponse` (te stoją WEWNĄTRZ grupy `web`);
        //   – 404 z ROUTERA, który do grupy `web` nigdy nie dochodzi;
        //   – 419 z ochrony CSRF i 429 z limitera;
        //   – 413/503 z globalnych `ValidatePostSize`
        //     i `PreventRequestsDuringMaintenance`.
        //
        // Dokładnie ten sam powód, dla którego `ApplySecurityHeaders` stoi
        // i tutaj, i w grupie `web`: połowa ekranów błędu do tamtej grupy
        // nie dociera.
        //
        // DLACZEGO NIE PIERWSZY. Bo `NormalizeForwardedFor` ma zostać
        // PIERWSZY (ustalenie W7-01 / SEC-01 wyżej) i nie ma powodu tego
        // ruszać: nad `PreventSharedSessionCache` zostają wtedy wyłącznie
        // dwie klasy, z których ŻADNA nie dokłada ciasteczka ani nagłówka
        // cache. Przesunięcie na pierwsze miejsce nie zmieniłoby ani jednej
        // odpowiedzi, a złamałoby ustalenie, które ktoś już raz mierzył.
        //
        // CZEGO TA KLASA NIE ROBI: nie usuwa ciasteczek. Odpowiedź z sesją
        // dalej niesie `Set-Cookie` — zmienia się wyłącznie to, komu wolno
        // ją przechować. Zakaz jest `private, no-store`, nie samo `private`:
        // `private` pozwala jeszcze przeglądarce odłożyć odpowiedź na dysk,
        // a to przy współdzielonym komputerze jest tym samym wyciekiem
        // o warstwę niżej.
        //
        // KASUJE TAKŻE `CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control`
        // i `Surrogate-Control`. Te nagłówki mają u brzegu PIERWSZEŃSTWO nad
        // `Cache-Control`, więc samo dopisanie `no-store` do `Cache-Control`
        // byłoby zakazem, który Cloudflare zignoruje.
        $middleware->prepend([
            NormalizeForwardedFor::class,
            ApplySecurityHeaders::class,
            PreventSharedSessionCache::class,
            CorrelateRequest::class,
        ]);

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
        // `X-Forwarded-Host` WYPADŁ Z TEJ LISTY 10 września 2026 (S2, D-071)
        // i to jest MOCNIEJSZE zamknięcie niż jakakolwiek lista hostów:
        // nagłówka, którego aplikacja nie czyta, nie da się podstawić.
        //
        // Powód, dla którego wolno go było wyjąć, jest jeden i konkretny:
        // W NASZYM ŁAŃCUCHU NIKT GO NIE WYSTAWIA I NIKT NIE PRZEPISUJE
        // `Host`. Cloudflare w trybie proxy przekazuje na origin `Host`
        // nietknięty (routing po nim właśnie działa), a brzeg Railway kieruje
        // ruch po `Host`/SNI i również go zachowuje — inaczej nie umiałby
        // odróżnić `kuking.pl` od `staging.kuking.pl` na tym samym koncie.
        // Aplikacja ma więc oryginalny host w `Host` i drugiego źródła
        // nie potrzebuje. Zmierzone przed zmianą: `X-Forwarded-Host:
        // attacker.invalid` wracało 200, a `url()` oddawało adres na
        // `attacker.invalid` — łącznie z linkiem w liście potwierdzającym
        // nowy adres e-mail, który powstaje w kontekście żądania HTTP.
        //
        // Gdyby kiedyś okazało się, że coś w łańcuchu JEDNAK przepisuje
        // `Host` (objaw: adresy w serwisie wskazują wewnętrzną domenę
        // platformy), to jest zmiana JEDNEJ linii — dopisanie
        // `Request::HEADER_X_FORWARDED_HOST` z powrotem. Ale wtedy trzeba
        // wrócić także do D-071 i zapisać pomiar, a nie dopisywać nagłówka
        // „na wszelki wypadek".
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // DRUGA POŁOWA TEJ SAMEJ GRANICY (S2, D-071): sam nagłówek `Host`.
        //
        // Bez `TrustHosts` Laravel odpowiada na DOWOLNY host i używa go do
        // budowy adresów bezwzględnych w trakcie żądania. To jest formalnie
        // otwarta granica zaufania, którą OWASP opisuje jako powierzchnię
        // zatruwania linków resetu hasła i przekierowań.
        //
        // Lista i uzasadnienie KAŻDEGO wpisu (razem z tym, co się stanie po
        // pominięciu któregoś) stoją w `App\Support\ZaufaneHosty` —
        // najważniejszy jest `healthcheck.railway.app`, bez którego KAŻDY
        // deploy pada na 400 i nigdy się nie kończy.
        //
        // `subdomains: false`, czyli lista jest DOKŁADNIE tym, co widać
        // w tamtej klasie. Z `true` Laravel dokleiłby jeszcze wzorzec
        // „wszystkie subdomeny hosta z `APP_URL`" i lista przestałaby być
        // sprawdzalna z jednego miejsca.
        //
        // CALLABLE, NIE TABLICA, i to nie jest kosmetyka: `bootstrap/app.php`
        // wykonuje się PRZED wczytaniem konfiguracji, a lista czyta
        // `config('app.url')` i `config('proxy.dodatkowe_hosty')`. Tablica
        // policzona tutaj byłaby policzona za wcześnie — Laravel woła to
        // wywołanie zwrotne dopiero w middleware, czyli w trakcie żądania.
        $middleware->trustHosts(at: ZaufaneHosty::wzorce(...), subdomains: false);

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

        // DWIE PODMIANY W GRUPIE `web`, NIE DOPISKI (#597).
        //
        // Obie klasy DZIEDZICZĄ po frameworkowych. Gdyby je tylko dopisać,
        // w stosie stałyby DWIE sesje i DWIE ochrony CSRF: rodzic dalej
        // zakładałby trwałą sesję i wystawiał `Set-Cookie` przy odczycie
        // zdjęcia, czyli dokładnie to, co ta zmiana usuwa. Dlatego
        // `replaceInGroup`, a nie `appendToGroup`.
        //
        // `replaceInGroup`, A NIE `replace`: `replace()` działa wyłącznie na
        // stosie GLOBALNYM (`Middleware::getGlobalMiddleware`), a obie
        // frameworkowe klasy siedzą w GRUPIE `web`
        // (`Middleware::getMiddlewareGroups`). `replace()` po cichu nie
        // zrobiłby nic — nie rzuca błędu, gdy nie trafi.
        //
        // KOLEJNOŚĆ W STOSIE ZOSTAJE BEZ ZMIAN. `Kernel::$middlewarePriority`
        // wymienia `Illuminate\Session\Middleware\StartSession`, a nie naszą
        // klasę — ale `SortedMiddleware::middlewareNames()` sprawdza też
        // `class_parents()`, więc podklasa dziedziczy pozycję rodzica.
        // Sesja nadal wstaje przed `ShareErrorsFromSession`, ochroną CSRF
        // i `SubstituteBindings`.
        //
        // LISTA WYJĄTKÓW CSRF NIŻEJ DZIAŁA DALEJ: `validateCsrfTokens()`
        // woła `PreventRequestForgery::except()`, a to jest właściwość
        // STATYCZNA rodzica, wspólna dla podklasy.
        //
        // CO SIĘ ZMIENIA POZA ZDJĘCIAMI: NIC.
        // `StartSessionExceptAnonymousMedia` schodzi z drogi rodzica tylko
        // przy `GET`/`HEAD` na trasie `media.show`, i to wyłącznie wtedy, gdy
        // żądanie nie ma ŻADNEGO ciasteczka, nagłówka `Authorization` ani
        // zalogowanego widza. Każde inne żądanie — HTML, formularz, logowanie,
        // panel, błędne albo obce ciasteczko — idzie przez `parent::handle()`
        // bez zmiany. `PreventRequestForgeryExceptMediaCookie` nadpisuje
        // WYŁĄCZNIE `addCookieToResponse()`, czyli wystawianie ciasteczka
        // `XSRF-TOKEN`; sama WALIDACJA tokenu (`handle()`, `tokensMatch()`,
        // `hasValidOrigin()`) zostaje nietknięta w rodzicu. POST bez tokenu
        // dalej kończy się na 419 — pilnuje tego
        // `CloudflareCachePrivacyTest::test_post_nadal_wymaga_csrf_po_bezsesyjnym_zdjeciu`,
        // który świadomie wyłącza testowy skrót `runningUnitTests()`.
        $middleware->replaceInGroup(
            'web',
            StartSession::class,
            StartSessionExceptAnonymousMedia::class,
        );

        $middleware->replaceInGroup(
            'web',
            PreventRequestForgery::class,
            PreventRequestForgeryExceptMediaCookie::class,
        );

        $middleware->alias([
            'moderator' => EnsureUserIsModerator::class,
            // Zawsze DRUGI w trasie, po 'moderator' — issue #12, patrz
            // komentarz klasy: zakłada, że użytkownik jest już moderatorem.
            'moderator.2fa' => EnsureModeratorHasTwoFactor::class,
        ]);

        // DWA adresy wyjęte spod ochrony CSRF — i oba dlatego, że żąda ich
        // ktoś, kto tokenu nie ma skąd wziąć, a nie dlatego, że tak wygodniej. (Wcześniej stał tu komentarz obiecujący, że po wygaśnięciu
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
        //
        // `podsumowanie/wypisz/*` — wypisanie z tygodniowego podsumowania
        // metodą POST (issue #11, D-057). Ten adres wołają GMAIL I OUTLOOK,
        // nie przeglądarka: nagłówki `List-Unsubscribe` i
        // `List-Unsubscribe-Post` (RFC 8058) każą klientowi pocztowemu
        // wysłać puste `POST` prosto z widoku listu, bez sesji i bez
        // odwiedzania strony. Żądanie z tokenem CSRF jest tam fizycznie
        // niemożliwe. Ochroną tej trasy jest PODPIS w adresie
        // (`middleware('signed')`), więc nie zostaje ona bez zabezpieczenia
        // — zmienia się tylko to, czym jest zabezpieczona. Trasa robi jedną
        // rzecz i wyłącznie na korzyść właściciela skrzynki: wyłącza wysyłkę.
        //
        // Droga POWROTNA (`podsumowanie/wracam/*`) tu NIE JEST wymieniona
        // i nie ma być: klika ją człowiek na naszej stronie, więc token ma,
        // a bez ochrony CSRF byłaby drogą do ZAPISANIA kogoś z powrotem.
        /*
         * `wejdz/facebook/odebranie-dostepu` — woła to serwer Facebooka,
         * nie przeglądarka człowieka: nie ma sesji, nie ma ciasteczka, nie ma
         * skąd wziąć tokenu. Autentyczność potwierdza PODPIS `signed_request`
         * sprawdzany na sekrecie aplikacji przez `hash_equals`, a nie sesja —
         * uzasadnienie w `FacebookDeauthorizeController` (issue #259).
         */
        $middleware->validateCsrfTokens(except: [
            '_csp',
            'podsumowanie/wypisz/*',
            'wejdz/facebook/odebranie-dostepu',
        ]);
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

            // --------------------------------------------------------------
            //  ...I ODDAJE CZŁOWIEKOWI TO, CO NAPISAŁ (audyt zewnętrzny, G06)
            // --------------------------------------------------------------
            //
            //  Do tej pory ekran 429 mówił „nic nie przepadło", a serwer nie
            //  zachowywał NICZEGO: `old()` puste, tekstu nigdzie w odpowiedzi,
            //  zdjęcia nie było jak odzyskać. Powrót „wstecz" bywa ratunkiem,
            //  ale to zachowanie przeglądarki, nie obietnica aplikacji —
            //  a obietnica stała wypisana na ekranie.
            //
            //  Najgorszy moment na taki komunikat: ktoś napisał przepis,
            //  kliknął „Opublikuj", trafił na limit i przeczytał, że wszystko
            //  jest w porządku. AGENTS.md §5: poprawnie wpisane dane nigdy
            //  nie znikają — bez wyjątku dla limitu zapytań.
            //
            //  TA SAMA DROGA CO PRZY 419: `OdzyskanyFormularz` wystawia
            //  formularz jeszcze raz, z treścią wprost z odbitego żądania.
            //  Nic nie ląduje w sesji ani w cache'u, więc limit nie zamienia
            //  się w sposób na odkładanie danych w bazie — treść żyje
            //  wyłącznie w tej jednej odpowiedzi HTTP.
            //
            //  ODZYSKUJEMY TYLKO NA TRASACH TREŚCI. Decyduje `OdzyskiwalneDane`
            //  (zgoda po nazwie trasy, nie zakaz po nazwie pola), więc 429 na
            //  logowaniu, rejestracji i drugim składniku nie oddaje niczego —
            //  a limit logowania jest właśnie tym, który odbija najczęściej.
            //
            //  DLACZEGO NIE `back()->withInput()`: powody stoją w komentarzu
            //  `App\Exceptions\OdzyskanyFormularz` i są tu te same. Doszedł
            //  jeden własny: `back()` wraca na formularz, a wejście na
            //  formularz bywa liczone przez ten sam limiter co jego wysłanie —
            //  czyli odbicie od limitu odsyłałoby prosto w kolejne odbicie.
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            $formularz = OdzyskanyFormularz::zZadania($request);

            // NAGŁÓWKI Z WYJĄTKU LECĄ DALEJ: `Retry-After` (i `X-RateLimit-*`)
            // to jedyne miejsce, z którego widok wie, na ile ta przerwa jest.
            // Rysując odpowiedź ręcznie, trzeba je przepisać — inaczej ekran
            // mówiłby „za kilka minut" zamiast „za 2 min".
            return response()->view('errors.429', [
                'formularz' => $formularz,
                'sekundy' => $e->getHeaders()['Retry-After'] ?? null,
            ], 429, $e->getHeaders());
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
        //  wiadomość WYŁĄCZNIE z klasy wyjątku, kodu błędu, pliku:linii,
        //  wzorca trasy i odcisku — nigdy z komunikatu wyjątku, nigdy
        //  z `$request->all()`, sesji, ciasteczek, adresu IP ani
        //  identyfikatora użytkownika.
        //
        //  KOMUNIKAT WYPADŁ Z TEJ LISTY 9 września i to była poprawka błędu,
        //  nie zaostrzenie zasady: przy `QueryException` komunikat buduje
        //  sterownik i wkłada w niego SQL razem z wartościami, czyli e-mail
        //  i hash hasła. Pełne uzasadnienie: komentarz klasy
        //  `App\Logging\WebhookBleduHandler`.
        $exceptions->report(function (Throwable $e) {
            if (blank(config('logging.channels.blad_webhook.url'))) {
                return;
            }

            // NAZWA KLASY, nie `$e->getMessage()` — druga linia obrony po
            // stronie wywołania. `WebhookBleduHandler` i tak nie czyta
            // `$record->message`, gdy w kontekście jest obiekt wyjątku, ale
            // ten argument PRZECHODZIŁ dotąd przez cały mechanizm logowania
            // z komunikatem, który przy `QueryException` niesie e-mail i hash
            // hasła (A6-01). Skoro nie jest do niczego potrzebny, nie ma po co
            // go tu wkładać.
            Log::channel('blad_webhook')->error($e::class, [
                'exception' => $e,
                ...app(QueueCorrelation::class)->forException($e),
            ]);
        });

        // Wygaśnięcie sesji to zdarzenie normalne, nie awaria. Zgłaszanie go
        // zasypywałoby log (i webhook błędów) szumem, w którym utonęłyby
        // prawdziwe błędy — a przy okazji jest to jedyny wyjątek, któremu
        // towarzyszy treść wpisana przez człowieka. Do logów nie ma ona po co
        // trafiać.
        $exceptions->dontReport(TokenMismatchException::class);
    })->create();
