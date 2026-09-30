<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use Carbon\CarbonInterface;

/**
 * Jedna pozycja kanału Atom (#2227): wpis albo przepis widoczny dla gościa.
 *
 * Tylko to, co gość widzi na stronie tej treści: tytuł, tekst, nazwa
 * autora i odnośnik do jego profilu, publiczny adres zdjęcia. Bez adresu
 * e-mail, bez notatek z zeszytu i bez tego, kto pozycję do zeszytu dodał.
 */
final readonly class PozycjaKanalu
{
    public function __construct(
        public string $id,
        public string $tytul,
        public string $adres,
        public ?CarbonInterface $opublikowano,
        public CarbonInterface $zmieniono,
        public string $autorNazwa,
        public ?string $autorAdres,
        public ?string $streszczenie,
        public ?string $zdjecie,
    ) {}
}
