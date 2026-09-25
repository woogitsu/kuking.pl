<?php

declare(strict_types=1);
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Laravel Sanctum — WYŁĄCZNIE tokeny osobistego dostępu (D-014, D-270)
|--------------------------------------------------------------------------
|
| Sanctum umie dwie rzeczy: tokeny w nagłówku `Authorization: Bearer …`
| i „SPA na ciasteczku sesji". Używamy tylko pierwszej. Aplikacja mobilna
| nie ma przeglądarkowego ciasteczka, a druga droga otwierałaby `/api/*`
| na sesję z WWW — czyli na CSRF, przed którym API nie ma żadnej osłony,
| bo jej nie potrzebuje, dopóki ciasteczka nie czyta.
|
| Każda z trzech wartości niżej domyka tę drugą drogę z innej strony.
*/

return [

    /*
     * PUSTA LISTA DOMEN „stanowych". Domyślnie Sanctum traktuje żądania
     * z `localhost` i z `APP_URL` jako SPA i dokłada im sesję z ciasteczka.
     * U nas nie ma SPA (AGENTS.md §3), więc żadna domena tego nie dostaje.
     */
    'stateful' => [],

    /*
     * PUSTA LISTA STRAŻNIKÓW SESJI. `Laravel\Sanctum\Guard` najpierw pyta
     * każdego strażnika z tej listy, czy ktoś jest zalogowany, a dopiero
     * potem czyta token. Z `['web']` zalogowana przeglądarka przechodziłaby
     * przez `auth:sanctum` bez tokenu. Pusta lista znaczy: token albo nic.
     */
    'guard' => [],

    /*
     * Wygasanie tokenu liczone od `created_at`, w minutach. `null`, bo
     * token aplikacji mobilnej ma działać jak „zapamiętaj mnie" — osoba
     * 50+ wylogowana co miesiąc z telefonu przestaje z niego korzystać.
     * Ochroną jest lista „Urządzenia z dostępem" w ustawieniach konta
     * (odwołanie jednym przyciskiem) i kasowanie tokenów razem z sesjami
     * (`User::invalidateSessions()`), nie termin.
     */
    'expiration' => null,

    /*
     * Przedrostek jawnej części tokenu. Po nim skanery sekretów (GitHub
     * secret scanning i podobne) rozpoznają token wklejony do repozytorium
     * albo zgłoszenia — bez przedrostka to zwykły losowy ciąg.
     */
    'token_prefix' => 'kuking_',

    /*
     * Trasa `/sanctum/csrf-cookie` istnieje tylko dla SPA na ciasteczku.
     * Nie rejestrujemy jej — to byłby adres bez żadnego zastosowania.
     */
    'routes' => false,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
