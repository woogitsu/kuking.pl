<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Wynik `ZdjeciaKreatora::przyjmij()` (issue #1387, krok 7).
 *
 * `mediaIdGlownego` to `null`, gdy zdjęcia głównego nie wybrano ALBO odpadło —
 * komponent wtedy NIE rusza dotychczasowego `heroMediaId` (zachowane zdjęcie
 * zostaje po błędzie). To samo dotyczy kroków: tylko przyjęte trafiają do
 * `mediaIdKrokow`. `bledy` mają klucze pól Livewire (`heroPhoto`,
 * `steps.N.photo`), w kolejności: najpierw zdjęcie główne, potem kroki.
 */
final readonly class WynikZdjecKreatora
{
    /**
     * @param  array<int|string, string>  $mediaIdKrokow  indeks wiersza => identyfikator przyjętego zdjęcia
     * @param  array<string, string>  $bledy  klucz pola => komunikat po polsku
     */
    public function __construct(
        public ?string $mediaIdGlownego,
        public array $mediaIdKrokow,
        public array $bledy,
    ) {}

    public function udany(): bool
    {
        return $this->bledy === [];
    }
}
