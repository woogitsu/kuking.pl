<?php

declare(strict_types=1);

namespace App\Domain\Collections\Odzyskiwanie;

use App\Models\Collection;

/**
 * Wynik `OdzyskajUsunietyZeszyt::handle()` (#2567): odzyskany zeszyt,
 * informacja, że zrobiło to już wcześniejsze wysłanie, i liczba zapisów, które
 * nie wróciły, bo ich przepis albo wpis już nie istnieje.
 */
final readonly class WynikOdzyskaniaZeszytu
{
    public function __construct(
        public Collection $zeszyt,
        public bool $juzOdzyskany,
        public int $zapisyNieWrocily,
        public int $zapisyWrocily,
    ) {}
}
