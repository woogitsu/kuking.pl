<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;

/**
 * Rozstrzygnięcie „Zapisuję" przy przepisie: albo wróciło to, co przed chwilą
 * wyjęto (`zdaniePowrotu`, bez zeszytu), albo przepis trafił do `zeszyt`.
 */
final readonly class WynikZapisuDoZeszytu
{
    public function __construct(
        public ?Collection $zeszyt,
        public ?string $zdaniePowrotu,
    ) {}
}
