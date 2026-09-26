<?php

declare(strict_types=1);

namespace App\Domain\Import;

/** Zarezerwowana kwota budżetu pod konkretnym dniem (D-297). */
final class Rezerwacja
{
    public function __construct(
        public readonly string $dzien,
        public readonly int $mikroUsd,
    ) {}
}
