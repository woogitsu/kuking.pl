<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use Illuminate\Support\Carbon;

/**
 * Jedna pozycja prywatnej listy „Moje rozmowy” (#2432): ostatnia własna
 * wypowiedź w wątku, krótki kontekst treści (już po bramce Policy) i adres
 * z numerem strony oraz kotwicą.
 */
final readonly class PozycjaRozmowy
{
    public function __construct(
        public string $idKomentarza,
        public bool $jestOdpowiedzia,
        public string $fragment,
        public string $kontekst,
        public Carbon $data,
        public string $adres,
    ) {}
}
