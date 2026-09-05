<?php

declare(strict_types=1);

use App\Http\Middleware\ApplySecurityHeaders;
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
        $middleware->web(append: [
            ApplySecurityHeaders::class,
        ]);

        $middleware->alias([
            'moderator' => EnsureUserIsModerator::class,
        ]);

        // Gdy sesja wygaśnie w trakcie wypełniania formularza, użytkownik
        // wraca na tę samą stronę z wpisanymi danymi, a nie na ekran błędu.
        $middleware->validateCsrfTokens(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
