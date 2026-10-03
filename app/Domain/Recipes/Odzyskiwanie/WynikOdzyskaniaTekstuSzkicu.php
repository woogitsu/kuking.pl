<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzyskiwanie;

/** Wynik przywrócenia tekstu szkicu (#2512): stan z `PunktOdzyskaniaSzkicu` i opcjonalny komunikat. */
final readonly class WynikOdzyskaniaTekstuSzkicu
{
    public function __construct(
        public string $status,
        public ?string $komunikat,
    ) {}
}
