<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\KomunikatZaDuzaWysylka;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audyt A31: gdy ciało żądania przekracza `post_max_size`
        // z `docker/php.ini`, Laravel SAM już to wykrywa (globalny,
        // wbudowany middleware `ValidatePostSize`, uruchamiany przed
        // sesją i przed CSRF) i rzuca `PostTooLargeException`. Nie trzeba
        // (i nie wolno) pisać drugiej, własnej wersji tego porównania —
        // patrz `KomunikatZaDuzaWysylka`. Brakowało tylko polskiego,
        // konkretnego renderowania tego wyjątku — domyślnie dostawał
        // ogólną stronę błędu frameworka.
        //
        // Rejestracja `renderable()` normalnie idzie przez `withExceptions()`
        // w `bootstrap/app.php`, którego celowo nie ruszamy (poza zakresem
        // audytu) — `renderable()` daje się jednak dopisać do tego samego,
        // już skonfigurowanego handlera z DOWOLNEGO miejsca startowego
        // aplikacji, więc robimy to stąd.
        $this->app->make(ExceptionHandler::class)->renderable(
            function (PostTooLargeException $e, Request $request) {
                // Klient oczekujący JSON-a (API, fetch()) ma dostać JSON,
                // nie stronę HTML — `return null` oddaje sprawę domyślnej,
                // już istniejącej ścieżce renderowania Laravela.
                if ($request->expectsJson()) {
                    return null;
                }

                return KomunikatZaDuzaWysylka::odpowiedz($request);
            },
        );
    }
}
