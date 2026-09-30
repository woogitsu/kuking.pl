<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use Carbon\CarbonInterface;

/**
 * Jeden kanał Atom (#2227) — gotowe dane, bez zapytań. Składa je
 * `TresciKanalu`, zapisuje do XML-a `ZapisAtom`.
 */
final readonly class Kanal
{
    /** @param  list<PozycjaKanalu>  $pozycje */
    public function __construct(
        public string $id,
        public string $tytul,
        public string $podtytul,
        public string $adresStrony,
        public string $adresKanalu,
        public string $autorNazwa,
        public ?string $autorAdres,
        public CarbonInterface $zmieniono,
        public array $pozycje,
    ) {}
}
