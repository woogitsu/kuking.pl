<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Jedna rezerwacja budżetu = jeden wiersz księgi `ai_rezerwacje` (D-297,
 * D-298 „maszyna stanów”). Klucz idempotencji: `(importId, proba)`.
 */
final class Rezerwacja
{
    public function __construct(
        public readonly int $id,
        public readonly string $importId,
        public readonly int $proba,
        public readonly string $dzien,
        public readonly int $mikroUsd,
    ) {}
}
