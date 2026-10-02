<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Exceptions\BladDlaCzlowieka;

/**
 * Prywatna liczba faktycznie ugotowanych porcji przy własnym „Ugotowałem"
 * (issue #2540).
 *
 * Liczbę podaje kucharz, ręcznie. Nigdy nie wnioskujemy jej z adresu
 * (`?porcje=`), zapamiętanego wyboru, sesji gotowania, Planera ani z liczby
 * porcji przepisu — żadne z nich nie dowodzi, ile faktycznie ugotowano.
 *
 * Zakres jawny: od 0,5 do 100 porcji, najwyżej dwie cyfry po przecinku
 * (przecinek lub kropka). Te same granice stoją w CHECK-u migracji; górna
 * jest jak w `WyborPorcji`, dolna niższa, bo ugotować można pół porcji.
 */
final class PorcjeWykonania
{
    public const NAJMNIEJ = 0.5;

    public const NAJWIECEJ = 100.0;

    public const KOMUNIKAT_NIEZROZUMIALY = 'Nie rozumiemy tej liczby porcji. Wpisz samą liczbę, na przykład 4 albo 2,5 (najwyżej dwie cyfry po przecinku), albo zostaw pole puste.';

    public const KOMUNIKAT_ZA_MALO = 'Taka liczba porcji jest za mała. Wpisz co najmniej 0,5 albo zostaw pole puste.';

    public const KOMUNIKAT_ZA_DUZO = 'Taka liczba porcji jest za duża. Wpisz najwyżej 100 albo zostaw pole puste.';

    /**
     * Pusty napis i `null` znaczą „nie podano". Inaczej zwraca poprawną
     * liczbę albo rzuca zdanie mówiące, co zrobić.
     *
     * @throws BladDlaCzlowieka
     */
    public static function sprawdz(?string $wpisane): ?float
    {
        $wpisane = $wpisane === null ? null : trim($wpisane);

        if ($wpisane === null || $wpisane === '') {
            return null;
        }

        $blad = self::blad($wpisane);

        if ($blad !== null) {
            throw new BladDlaCzlowieka($blad);
        }

        return self::liczba($wpisane);
    }

    /** Komunikat o błędzie dla niepustego napisu albo `null`, gdy liczba jest poprawna. */
    public static function blad(string $wpisane): ?string
    {
        $liczba = self::liczba($wpisane);

        if ($liczba === null) {
            return self::KOMUNIKAT_NIEZROZUMIALY;
        }

        if ($liczba < self::NAJMNIEJ) {
            return self::KOMUNIKAT_ZA_MALO;
        }

        if ($liczba > self::NAJWIECEJ) {
            return self::KOMUNIKAT_ZA_DUZO;
        }

        return null;
    }

    /** Liczba do pola formularza: „8", „2,5". */
    public static function doPola(float $porcje): string
    {
        return WyborPorcji::doPola($porcje);
    }

    /** „8 porcji", „1 porcja", „2,5 porcji". */
    public static function etykieta(float $porcje): string
    {
        return WyborPorcji::etykieta($porcje);
    }

    private static function liczba(string $wpisane): ?float
    {
        $tekst = str_replace(',', '.', trim($wpisane));

        if (preg_match('/^\d{1,4}(?:\.\d{1,2})?$/', $tekst) !== 1) {
            return null;
        }

        return (float) $tekst;
    }
}
