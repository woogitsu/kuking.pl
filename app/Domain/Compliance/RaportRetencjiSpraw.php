<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

/**
 * Wynik jednego przebiegu {@see PrzedawnioneSprawyModeracyjne::posprzataj()}.
 *
 * Bez tego raportu nikt nie zauważy, że automat nagle kasuje zero albo
 * wszystko (wymóg zadania) — komenda drukuje te liczby wprost, zamiast
 * samego "gotowe".
 */
final class RaportRetencjiSpraw
{
    public function __construct(
        public readonly int $usunieteOdwolania,
        public readonly int $bledyOdwolan,
        public readonly int $usunieteDecyzje,
        public readonly int $bledyDecyzji,
        public readonly int $pominieteDecyzjeZywymOdwolaniem,
        public readonly int $usunieteZgloszenia,
        public readonly int $bledyZgloszen,
    ) {}
}
