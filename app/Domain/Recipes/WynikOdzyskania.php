<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;

/**
 * Wynik `OdzyskajUsunietyPrzepis::handle()` (#2620): odzyskany przepis,
 * informacja, że ktoś (np. drugie kliknięcie) zrobił to już wcześniej, i liczba
 * zdjęć, które nie wróciły, bo nie wolno ich już użyć.
 */
final readonly class WynikOdzyskania
{
    public function __construct(
        public Recipe $przepis,
        public bool $juzOdzyskany,
        public int $zdjeciaNieWrocily,
    ) {}
}
