<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Podstawia liczbę i poprawną polską odmianę rzeczownika do komunikatów
 * walidacji Laravela dla reguł `min` i `max` (issue #86).
 *
 * PO CO TO ISTNIEJE
 * `lang/pl/validation.php` jest zwykłą tablicą PHP — nie potrafi sama policzyć,
 * czy `:min`/`:max` ma dostać „znak", „znaki" czy „znaków" (Laravel wywołuje
 * zwykłe `Lang::get()`, nie `trans_choice()`, więc żaden automat odmiany nie
 * zadziała bez tego haka). Tablica trzyma więc wzorzec w postaci
 * `:odmiana(jeden|kilka|wiele)`, a ten hak — zarejestrowany jako
 * `Validator::replacer()` w `AppServiceProvider` — podstawia pod niego
 * właściwą formę.
 *
 * DLACZEGO NIE DRUGA WERSJA ODMIANY
 * Reguła liczebnika (2-4 → "kilka", nastki → "wiele") istnieje już
 * w `Odmiana::rzeczownik()`. Ten plik tylko ją WYWOŁUJE — nie liczy niczego
 * sam — żeby nie powstała trzecia kopia tej samej logiki (po `LimityZdjec`,
 * które miało własną, teraz też przepisaną na `Odmiana`).
 */
final class OdmianaWalidacji
{
    /**
     * `(?U)` (ungreedy) na wypadek gdyby w komunikacie było więcej niż jedno
     * takie wystąpienie — każde ma dopasować tylko swoje nawiasy, nie
     * wszystko od pierwszego `(` do ostatniego `)`.
     */
    private const WZORZEC = '/:odmiana\(([^|)]+)\|([^|)]+)\|([^|)]+)\)/u';

    public static function podstaw(string $komunikat, int $liczba): string
    {
        // `:min` i `:max` nigdy nie występują w tym samym komunikacie razem,
        // więc podmiana obu naraz jest bezpieczna i prostsza niż sprawdzanie,
        // z której reguły przyszło wywołanie.
        $komunikat = str_replace([':min', ':max'], (string) $liczba, $komunikat);

        return (string) preg_replace_callback(
            self::WZORZEC,
            static fn (array $dopasowanie): string => Odmiana::rzeczownik(
                $liczba,
                $dopasowanie[1],
                $dopasowanie[2],
                $dopasowanie[3],
            ),
            $komunikat,
        );
    }
}
