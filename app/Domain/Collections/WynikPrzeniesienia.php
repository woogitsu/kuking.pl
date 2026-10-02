<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;

/**
 * Wynik przeniesienia pozycji (#2430). `przeniesiono = false` znaczy: pozycja
 * była już w zeszycie docelowym (ponowione wysłanie) — nic nie zmieniono i nic
 * nie zduplikowano.
 */
final class WynikPrzeniesienia
{
    public function __construct(
        public readonly Collection $zrodlo,
        public readonly Collection $cel,
        public readonly string $tytul,
        public readonly bool $przeniesiono,
    ) {}
}
