<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Wynik jednego żądania do modelu „GPT-6 Luna” (D-298).
 *
 * Żądanie DOSZŁO i dostawca je policzył — dlatego `usage` jest tu zawsze,
 * gdy przyszło, także wtedy, gdy treść odpowiedzi nie nadaje się do użytku
 * (`dane === null`). Budżet rozlicza się z `usage` niezależnie od tego, czy
 * odczyt się udał (D-297).
 */
final class OdpowiedzModelu
{
    /**
     * @param  ?array<string, mixed>  $dane  JSON zgodny ze schematem, albo `null`
     * @param  array<string, mixed>  $surowa  odpowiedź do diagnozy (retencja 30 dni)
     */
    public function __construct(
        public readonly ?array $dane,
        public readonly ?int $tokenyWejscia,
        public readonly ?int $tokenyWyjscia,
        public readonly array $surowa,
        public readonly ?string $powodOdrzucenia = null,
    ) {}

    public function maUsage(): bool
    {
        return $this->tokenyWejscia !== null && $this->tokenyWyjscia !== null;
    }
}
