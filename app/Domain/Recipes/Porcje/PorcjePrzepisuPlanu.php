<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

use App\Domain\Planer\PorcjePrzepisuDlaPlanu;
use App\Models\Recipe;

/**
 * Implementacja `PorcjePrzepisuDlaPlanu` (#2509): zakres 1–100 i parser daje
 * istniejący `WyborPorcji`, nie osobna reguła.
 */
final class PorcjePrzepisuPlanu implements PorcjePrzepisuDlaPlanu
{
    public function porcje(Recipe $przepis, string $wartosc): float|string
    {
        $wybor = WyborPorcji::dla($przepis, $wartosc);

        if (! $wybor->dostepny()) {
            return self::BEZ_PODSTAWY;
        }

        if ($wybor->odrzucone || $wybor->wybrane === null || $wybor->wybrane < WyborPorcji::NAJMNIEJ || $wybor->wybrane > WyborPorcji::NAJWIECEJ) {
            return self::NIEPRAWIDLOWE;
        }

        return $wybor->wybrane;
    }
}
