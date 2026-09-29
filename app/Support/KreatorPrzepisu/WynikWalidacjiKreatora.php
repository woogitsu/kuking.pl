<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Wynik walidacji jednego etapu kreatora (issue #1387, krok 10).
 *
 * `sprawdzone` — klucze (także z gwiazdką), które komponent czyści w worku
 * błędów przed dodaniem nowych; `bledy` — klucz => komunikaty w kolejności
 * dodawania do worka.
 */
final readonly class WynikWalidacjiKreatora
{
    /**
     * @param  list<string>  $sprawdzone
     * @param  array<string, list<string>>  $bledy
     */
    public function __construct(
        public array $sprawdzone,
        public array $bledy,
    ) {}

    public function poprawny(): bool
    {
        return $this->bledy === [];
    }
}
