<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NIEPOPRAWNY UTF-8 I BAJT NUL TO HTTP 400, NIE 500 (audyt odporności, fuzz HTTP).
 *
 * `?q=%FF%FE%C3` albo pole formularza z bajtem `\0` dochodziło do PostgreSQL
 * (SQLSTATE 22021 „invalid byte sequence for encoding UTF8” — Postgres nie
 * przyjmuje też NUL w tekście) albo do funkcji PHP, które na takim napisie
 * rzucają wyjątek (`Str::squish()` zwraca `null` → TypeError, `DateTime`
 * → ValueError). Kilkanaście tras kończyło się HTTP 500 i alarmem na Discordzie.
 *
 * Łatanie każdego miejsca osobno (jak `FrazaWyszukiwania::normalizuj`) nie
 * domyka tematu — następny kontroler przekaże cudzy tekst do `where()`.
 * Dlatego jedna reguła na wejściu: klucze i wartości adresu oraz treści
 * formularza (także JSON, jeśli żądanie go niesie) muszą być poprawnym UTF-8
 * bez NUL. Przeglądarka i nasza aplikacja takich znaków nigdy nie wysyłają —
 * to ręcznie zbudowany adres albo bot, więc krótki komunikat zamiast strony
 * z formularzem wystarcza.
 *
 * CZEGO TA KLASA NIE RUSZA:
 *  - plików z uploadu (`$request->files`) — to binaria, sprawdzane
 *    zawartością (obrazy, ZIP-y);
 *  - surowego ciała (`getContent()`) — webhook z podpisem (Facebook
 *    `signed_request` to pole formularza, ASCII; raport CSP czyta surowe
 *    ciało) liczy podpis na bajtach i nie może być ruszany; sprawdzamy
 *    tylko rozłożone pola, a JSON tylko gdy żądanie ma nagłówek JSON;
 *  - nagłówków.
 *
 * Globalny, a nie w grupie `web`: dotyczy też API i Livewire, a nie potrzebuje
 * nazwy trasy.
 */
final class OdrzucNiepoprawneZnaki
{
    public function handle(Request $request, Closure $next): Response
    {
        $zrodla = [$request->query->all(), $request->request->all()];

        if ($request->isJson()) {
            $zrodla[] = $request->json()->all();
        }

        foreach ($zrodla as $zrodlo) {
            if (! self::czysto($zrodlo)) {
                abort(400, 'Adres albo formularz zawiera znaki, których nie da się odczytać. Wpisz tekst jeszcze raz, bez znaków specjalnych.');
            }
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $dane
     */
    public static function czysto(array $dane): bool
    {
        foreach ($dane as $klucz => $wartosc) {
            if (is_string($klucz) && ! self::poprawnyTekst($klucz)) {
                return false;
            }

            if (is_string($wartosc) && ! self::poprawnyTekst($wartosc)) {
                return false;
            }

            if (is_array($wartosc) && ! self::czysto($wartosc)) {
                return false;
            }
        }

        return true;
    }

    private static function poprawnyTekst(string $tekst): bool
    {
        return ! str_contains($tekst, "\0") && preg_match('//u', $tekst) === 1;
    }
}
