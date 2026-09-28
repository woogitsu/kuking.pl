<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\OdczytanyPrzepis;

final class OdczytanyPdf
{
    public function __construct(public readonly OdczytanyPrzepis $przepis, public readonly string $droga) {}
}
