<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\Recipe;

/**
 * Sprawdzenie liczby porcji wpisanej przy pozycji planu (#2509) tą samą regułą
 * co `?porcje=` na stronie przepisu.
 *
 * Kontrakt po stronie Planera, implementacja w `Recipes`
 * (`Porcje\PorcjePrzepisuPlanu`, na `WyborPorcji`), wiązanie
 * w `AppServiceProvider`. Bez niego import `Recipes` w Planerze zamykał cykl
 * `Planer → Recipes → Planer` (`GrafModulowDomenyBezCykliTest`).
 */
interface PorcjePrzepisuDlaPlanu
{
    /** Przepis nie podaje liczby porcji, więc nie ma od czego liczyć. */
    public const BEZ_PODSTAWY = 'bez_podstawy';

    /** Wpisana wartość nie jest liczbą z dozwolonego zakresu. */
    public const NIEPRAWIDLOWE = 'nieprawidlowe';

    /**
     * @return float|self::BEZ_PODSTAWY|self::NIEPRAWIDLOWE liczba porcji albo powód odmowy
     */
    public function porcje(Recipe $przepis, string $wartosc): float|string;
}
