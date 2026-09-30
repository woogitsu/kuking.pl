<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * TABLICA W PARAMETRZE ADRESU TO BRAK PARAMETRU (#2239 i rodzina, audyt BP-04).
 *
 * `?tydzien[]=x`, `?cursor[]=x`, `?status[x]=1` — PHP składa z tego tablicę.
 * Kontrolery i klasy żądań czytają parametry adresu jako tekst (`(string)`,
 * `trim()`, parametr typu `?string`, `where()`, kursor paginacji), więc taki
 * adres kończył się HTTP 500 i alarmem na Discordzie w kilkunastu miejscach:
 * planer, zeszyty, reset hasła, lista kont, profil, spiżarnia, powroty
 * z Google i Facebooka, kolejki moderacji, feedy.
 *
 * Poprawianie każdego miejsca osobno nie domyka tematu — następny kontroler
 * znowu napisze `(string) $request->query(...)`. Dlatego jedna reguła dla
 * całej grupy `web`: parametr adresu, który jest tablicą, znika z żądania,
 * zanim zobaczy go kontroler. Dla człowieka to to samo, co adres bez tego
 * parametru — strona pokazuje stan domyślny (bieżący tydzień, pierwszą
 * stronę feedu, całą listę), zamiast strony błędu. Dla GET to właściwa
 * odpowiedź: zepsuty odnośnik nie jest formularzem, nie ma pola, przy którym
 * pokazać błąd.
 *
 * Działa dla KAŻDEJ metody, ale tylko na części ADRESU. Treść formularza
 * (POST/PUT/DELETE) zostaje nietknięta — tam o kształcie pola rozstrzyga
 * walidacja albo `App\Support\Wejscie`, żeby człowiek zobaczył błąd przy
 * polu. `$request->input()` łączy treść z adresem, więc bez tej klasy tablica
 * dopisana do adresu akcji formularza (`POST /x?token[]=`) wracałaby tą drogą.
 *
 * WYJĄTKI: trasy, których formularz GET naprawdę wysyła listę (pola
 * `name="follow[]"`). Lista jest jawna i krótka; strażnik
 * `TabliceWParametrachNieDajaBledu500Test` sprawdza, że każdy formularz GET
 * z polem-listą, jaki renderuje serwis, ma tu swój wpis.
 */
final class ParametryAdresuBezTablic
{
    /**
     * Nazwa trasy => parametry adresu, które na niej wolno wysłać jako listę.
     *
     * @var array<string, list<string>>
     */
    public const LISTY_DOZWOLONE = [
        // Krok „Poznaj ludzi” (onboarding): zaznaczone osoby i ich
        // identyfikatory wracają przez GET, żeby wybór przeżył odświeżenie.
        'onboarding.people' => ['follow', 'oczekiwani'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $parametry = $request->query->all();
        $dozwolone = self::LISTY_DOZWOLONE[(string) $request->route()?->getName()] ?? [];
        $usuniete = false;

        foreach ($parametry as $klucz => $wartosc) {
            if (is_array($wartosc) && ! in_array((string) $klucz, $dozwolone, true)) {
                $request->query->remove((string) $klucz);
                unset($parametry[$klucz]);
                $usuniete = true;
            }
        }

        if ($usuniete) {
            // `fullUrl()` i `getQueryString()` czytają surowy QUERY_STRING,
            // nie worek parametrów — bez tego odnośniki budowane z bieżącego
            // adresu przenosiłyby usuniętą tablicę dalej.
            $request->server->set('QUERY_STRING', Arr::query($parametry));
        }

        return $next($request);
    }
}
