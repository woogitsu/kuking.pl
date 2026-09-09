<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\KomunikatZaDuzaWysylka;
use App\Support\OdmianaWalidacji;
use App\Support\Storage\DyskR2;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
        // Sterownik dysku `r2` — zapis do Cloudflare R2 BEZ nagłówka
        // `x-amz-acl` (issue #120, audyt G-02).
        //
        // Rejestracja stoi na początku `boot()`, przed czymkolwiek, co mogłoby
        // sięgnąć po dysk: `Storage::extend()` musi być znane wcześniej, niż
        // pierwszy `Storage::disk('r2')` zbuduje adapter. Dyski są tworzone
        // leniwie i zapamiętywane, więc rejestracja po pierwszym użyciu nie
        // zmieniłaby już nic — a wyglądałaby, jakby zmieniała.
        //
        // DLACZEGO NIE `register()`: rozstrzyganie fasady `Storage` w metodzie
        // `register()` wymusza zbudowanie menedżera dysków, zanim inni
        // dostawcy zdążą się zarejestrować. `boot()` jest właściwym miejscem
        // i to zaleca dokumentacja Laravela.
        Storage::extend('r2', fn ($app, array $konfiguracja) => DyskR2::utworz($konfiguracja));

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

        // Issue #86: `lang/pl/validation.php` nie potrafi sam odmienić
        // rzeczownika przy `:min`/`:max` (Laravel woła zwykłe `Lang::get()`,
        // nie `trans_choice()`), więc komunikaty size'owe niosą tam wzorzec
        // `:odmiana(jeden|kilka|wiele)`. Ten hak jest jedynym miejscem, które
        // go rozumie — i jedynym, które w ogóle PODSTAWIA `:min`/`:max`
        // rejestracja własnego replacera dla reguły wyłącza wbudowaną
        // podmianę Laravela dla TEJ reguły, więc musimy zrobić to sami
        // (patrz komentarz w `OdmianaWalidacji`).
        Validator::replacer(
            'min',
            fn (string $message, string $attribute, string $rule, array $parameters): string => OdmianaWalidacji::podstaw($message, (int) ($parameters[0] ?? 0)),
        );
        Validator::replacer(
            'max',
            fn (string $message, string $attribute, string $rule, array $parameters): string => OdmianaWalidacji::podstaw($message, (int) ($parameters[0] ?? 0)),
        );
    }
}
