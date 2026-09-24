<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Które strony HTML wolno oddać brzegowi Cloudflare do wspólnego cache (#610).
 *
 * JEDNO MIEJSCE, TRZY WARSTWY
 * Tę samą regułę czytają `StartSessionExceptAnonymousMedia` (czy w ogóle
 * zakładać sesję), `PreventRequestForgeryExceptMediaCookie` (czy wystawiać
 * `XSRF-TOKEN`) i `PreventSharedSessionCache` (jaki `Cache-Control` wysłać),
 * a widoki formularza motywu pytają ją, czy wypisać `@csrf`. Gdyby każda
 * warstwa miała własną listę tras, rozjazd oznaczałby stronę z sesją
 * i nagłówkiem `public` — czyli dokładnie wyciek, którego ta klasa pilnuje.
 *
 * KIEDY STRONA JEST PUBLICZNA — WSZYSTKIE WARUNKI NARAZ
 *  – `KUKING_HTML_EDGE_CACHE_SECONDS` > 0 (domyślnie 0: wyłączone, zachowanie
 *    identyczne jak przed #610);
 *  – trasa z listy `TRASY` i metoda GET/HEAD;
 *  – pusty query string (paginacja, `?q=`, znaczniki kampanii — zostają
 *    dynamiczne; ta sama granica stoi w regule Cloudflare);
 *  – ŻADNEGO ciasteczka (nie tylko sesji: motyw, skala tekstu i remember-me
 *    zmieniają HTML), żadnego `Authorization`, żadnego zalogowanego.
 *
 * Odpowiedź dostaje `public` dopiero w `PreventSharedSessionCache`, i tylko
 * wtedy, gdy jest 200, nie ustawia żadnego ciasteczka i nie zawiera tokenu
 * CSRF (`zawieraStanKlienta()`). Każda niepewność kończy się `no-store`.
 */
final class PublicznyHtmlGoscia
{
    public const ATRYBUT = 'kuking_stateless_public_html';

    /** Górna granica, której nie przestawi żadna zmienna środowiskowa. */
    public const MAKS_SEKUND = 300;

    /**
     * Landing, przepis i profil publiczny — strony, na które wchodzi się
     * z wyszukiwarki. `/odkryj`, `/szukaj`, listy obserwujących i tryb
     * gotowania świadomie NIE: to nie jest ruch z Google, a każda trasa
     * więcej to kolejne okno nieświeżości do nazwania.
     */
    public const TRASY = ['landing', 'recipes.show', 'profile.show'];

    public static function sekundy(): int
    {
        return max(0, min(self::MAKS_SEKUND, (int) config('kuking.html_cache.edge_seconds', 0)));
    }

    public static function kwalifikuje(Request $request): bool
    {
        return self::sekundy() > 0
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->routeIs(...self::TRASY)
            && $request->getQueryString() === null
            && ! $request->headers->has('Cookie') && $request->cookies->count() === 0
            && ! $request->headers->has('Authorization')
            && $request->user() === null;
    }

    /** Czy to żądanie zostało obsłużone bez sesji (ustawia StartSession). */
    public static function bezSesji(?Request $request = null): bool
    {
        return ($request ?? request())->attributes->get(self::ATRYBUT) === true;
    }

    /**
     * Ostatni bezpiecznik: HTML z tokenem CSRF albo meta `csrf-token` nie
     * może trafić do wspólnego cache, nawet gdyby ktoś dopisał formularz
     * do tych stron i zapomniał o `bezSesji()`.
     */
    public static function zawieraStanKlienta(Response $response): bool
    {
        $tresc = $response->getContent();

        return ! is_string($tresc)
            || str_contains($tresc, 'name="_token"')
            || str_contains($tresc, 'csrf-token');
    }
}
