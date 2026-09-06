<?php

declare(strict_types=1);

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsModerator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        // Gdy sesja wygaśnie w trakcie wypełniania formularza, użytkownik
        // wraca na tę samą stronę z wpisanymi danymi, a nie na ekran błędu.
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
    })->create();
