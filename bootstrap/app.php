<?php

declare(strict_types=1);

use App\Exceptions\OdzyskanyFormularz;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsModerator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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
        // `at: '*'` znaczy „ufaj tej maszynie, która się właśnie połączyła".
        // Jest tu bezpieczne, bo kontener nie ma publicznego adresu — dojść
        // do niego można wyłącznie przez brzeg platformy. Lista konkretnych
        // adresów IP nie wchodzi w grę: Railway ich nie gwarantuje, a zakresy
        // Cloudflare zmieniają się bez zapowiedzi.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            ApplySecurityHeaders::class,

            // GLOBALNIE, nie wybiórczo na kontrolerach (issue #39).
            // Wybiórczo znaczy: następny nowy kontroler o tym zapomni, a brak
            // tej kontroli niczego nie wywala — po prostu przepuszcza konto,
            // które miało być odcięte. Middleware sam sprawdza, czy ktoś jest
            // zalogowany, więc na trasach gościa nie robi nic.
            EnsureAccountIsActive::class,
        ]);

        $middleware->alias([
            'moderator' => EnsureUserIsModerator::class,
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

        // Wygaśnięcie sesji to zdarzenie normalne, nie awaria. Zgłaszanie go
        // zasypywałoby log (i Sentry) szumem, w którym utonęłyby prawdziwe
        // błędy — a przy okazji jest to jedyny wyjątek, któremu towarzyszy
        // treść wpisana przez człowieka. Do logów nie ma ona po co trafiać.
        $exceptions->dontReport(TokenMismatchException::class);
    })->create();
