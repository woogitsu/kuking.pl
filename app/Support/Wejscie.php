<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Odczyt pola formularza, które MA być tekstem, a może przyjść jako tablica
 * (#2239 i rodzina, audyt BP-04).
 *
 * `name="email[]"` albo `email[a]=` w treści żądania daje w PHP tablicę.
 * Rzutowanie `(string)` zamienia to w „Array to string conversion", Laravel
 * robi z ostrzeżenia wyjątek i człowiek (albo automat) dostaje HTTP 500,
 * a Discord alarm. `$request->string()` NIE pomaga: `Stringable` też robi
 * `(string)` na tablicy.
 *
 * Parametry ADRESU (query string) czyści wcześniej, dla całej grupy `web`,
 * `App\Http\Middleware\ParametryAdresuBezTablic` — tablica w adresie jest
 * tam traktowana jak brak parametru. Ta klasa jest dla TREŚCI formularza
 * (POST/PUT/DELETE), gdzie o kształcie pola wie tylko kontroler.
 *
 * Dwie drogi, zależnie od tego, co ma zobaczyć człowiek:
 *
 *  - `tekst()` — pole, którego nikt nie wypełnia ręcznie (token, wersja,
 *    identyfikator w ukrytym polu): nie-tekst to wartość domyślna, a dalej
 *    działa zwykła ścieżka „pusty/nieaktualny”;
 *  - `normalizujTekst()` — pole, które przed walidacją jest normalizowane
 *    (e-mail, nazwa konta): tekst jest normalizowany, a tablica zostaje
 *    NIETKNIĘTA, żeby reguła `string` walidatora pokazała błąd przy polu.
 */
final class Wejscie
{
    /**
     * Tekst albo liczba z formularza jako napis; każda inna wartość
     * (tablica, `null`, obiekt z JSON-a) to `$domyslna`.
     */
    public static function tekst(mixed $wartosc, string $domyslna = ''): string
    {
        if (is_string($wartosc)) {
            return $wartosc;
        }

        if (is_int($wartosc) || is_float($wartosc)) {
            return (string) $wartosc;
        }

        return $domyslna;
    }

    /**
     * Normalizuje wartość tylko wtedy, gdy jest tekstem. Nie-tekst oddaje bez
     * zmian — rozstrzyga o nim walidacja (reguła `string`), nie rzutowanie.
     *
     * @param  callable(string): string  $normalizuj
     */
    public static function normalizujTekst(mixed $wartosc, callable $normalizuj): mixed
    {
        if ($wartosc === null) {
            return $normalizuj('');
        }

        return is_string($wartosc) ? $normalizuj($wartosc) : $wartosc;
    }
}
