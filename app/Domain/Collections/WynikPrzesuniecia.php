<?php

declare(strict_types=1);

namespace App\Domain\Collections;

/**
 * Co się stało po „Wyżej" / „Niżej" / „Na początek" / „Na koniec" (#2544).
 *
 * `pozycja` i `ile` liczą WIDOCZNE przepisy (to, co człowiek ma na ekranie),
 * a `zmieniono = false` znaczy: przepis już stał tam, dokąd miał trafić.
 */
final readonly class WynikPrzesuniecia
{
    public function __construct(
        public string $tytul,
        public int $pozycja,
        public int $ile,
        public bool $zmieniono,
    ) {}

    /** Strona zeszytu (po `KolejnoscPrzepisow::NA_STRONE`), na której przepis stoi teraz. */
    public function strona(): int
    {
        return intdiv(max(1, $this->pozycja) - 1, KolejnoscPrzepisow::NA_STRONE) + 1;
    }
}
