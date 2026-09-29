<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

/**
 * Co się stało po „Wczytaj zaznaczone”: same liczby i zdania dla człowieka.
 */
final readonly class WynikWczytania
{
    /**
     * @param  array<string, int>  $utworzone  liczba utworzonych pozycji wg rodzaju (przepis, wpis, zeszyt)
     * @param  list<string>  $niewczytane  pozycje, których nie udało się utworzyć, z powodem po polsku
     * @param  int  $zostalo  ile zaznaczonych pozycji czeka na kolejne wczytanie (limit jednej partii)
     */
    public function __construct(
        public array $utworzone,
        public int $juzByly,
        public array $niewczytane,
        public int $zostalo,
    ) {}

    public function razem(): int
    {
        return array_sum($this->utworzone);
    }
}
