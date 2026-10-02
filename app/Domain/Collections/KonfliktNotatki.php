<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use RuntimeException;

/**
 * Notatka przy pozycji zeszytu zmieniła się od chwili, w której człowiek
 * zobaczył formularz (#2400): druga karta albo współtwórca wspólnego zeszytu.
 * Nic nie zostało zapisane; wyjątek niesie aktualną treść do pokazania.
 */
final class KonfliktNotatki extends RuntimeException
{
    public function __construct(public readonly ?string $aktualna)
    {
        parent::__construct('Notatka zmieniła się w międzyczasie.');
    }
}
