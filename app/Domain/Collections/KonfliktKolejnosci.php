<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use RuntimeException;

/**
 * Kolejność przepisów w zeszycie zmieniła się od chwili, w której człowiek
 * zobaczył kartę (#2544): druga karta przeglądarki, dopisany albo wyjęty
 * przepis, podwójne kliknięcie. Nic nie zostało przesunięte.
 */
final class KonfliktKolejnosci extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Kolejność w zeszycie zmieniła się w międzyczasie.');
    }
}
