<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wybór alergenów w wyszukiwarce żyje WYŁĄCZNIE w adresie jednego żądania
 * (#1902, D-333, D-299).
 *
 * Filtr „Bez wskazanych alergenów (według autorów)” jest bezstanowy: nie ma
 * profilu alergii szukającego (dane o zdrowiu, art. 9 RODO), więc nic z wyboru
 * nie może zostać po stronie serwera dłużej niż trwa żądanie. Jedno miejsce
 * łamało tę zasadę bez wiedzy kodu aplikacji: Laravel po każdym żądaniu GET
 * zapisuje w SESJI pełny adres (`_previous.url`, z niego korzysta `back()`).
 * Adres `/szukaj?bez[]=milk&…` lądowałby więc w tabeli sesji razem z kontem.
 *
 * Ten middleware zdejmuje parametr `bez` z adresu ZAPISYWANEGO W SESJI —
 * po wyrenderowaniu odpowiedzi, a przed zapisem sesji (`StartSession` zapisuje
 * adres dopiero po powrocie z całego stosu trasy). Ekran, odnośniki i wynik
 * wyszukiwania są już policzone z pełnego adresu, więc człowiek nic nie
 * zauważa; traci się tylko to, że `back()` z kolejnego ekranu nie odtworzy
 * filtra. Tak samo postępuje `ParametryAdresuBezTablic` ze swoim śladem.
 */
final class NieZapamietujeWyboruAlergenow
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->query->has('bez')) {
            $pozostale = $request->query->all();
            unset($pozostale['bez']);

            // `fullUrl()` i `getQueryString()` czytają surowy QUERY_STRING.
            $request->server->set('QUERY_STRING', Arr::query($pozostale));
        }

        return $response;
    }
}
