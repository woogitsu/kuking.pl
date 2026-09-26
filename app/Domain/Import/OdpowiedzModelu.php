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

    /**
     * Odpowiedź odtworzona z `importy_przepisow.odpowiedz_modelu` — dla
     * ponowienia zadania, które NIE woła modelu drugi raz (#1980).
     *
     * `dane` wracają z zapisanego `output_text` tą samą regułą, co
     * w `KlientLuna`: tylko `status = completed` i tylko poprawny JSON.
     * Odmowa i odpowiedź niepełna mają `output_text = null`, więc wracają
     * jako `dane = null` — tak samo jak za pierwszym razem.
     *
     * @param  array<string, mixed>  $surowa
     */
    public static function zZapisanej(array $surowa, ?int $tokenyWejscia, ?int $tokenyWyjscia): self
    {
        $tekst = ($surowa['status'] ?? null) === 'completed' && is_string($surowa['output_text'] ?? null)
            ? $surowa['output_text']
            : null;
        $dane = $tekst === null ? null : json_decode($tekst, true);

        return is_array($dane)
            ? new self($dane, $tokenyWejscia, $tokenyWyjscia, $surowa)
            : new self(null, $tokenyWejscia, $tokenyWyjscia, $surowa, 'zapisana_bez_danych');
    }

    public function maUsage(): bool
    {
        return $this->tokenyWejscia !== null && $this->tokenyWyjscia !== null;
    }
}
