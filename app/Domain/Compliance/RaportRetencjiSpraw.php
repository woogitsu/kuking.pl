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
        // Kandydaci, którzy nie zmieścili się w budżecie przebiegu albo których
        // skasowanie padło — podejmie ich następny przebieg (issue #998).
        public readonly int $pozostaloNaKolejnyPrzebieg = 0,
    ) {}

    /** Łącznie wierszy, których nie udało się skasować we wszystkich trzech tabelach. */
    public function bledyLacznie(): int
    {
        return $this->bledyOdwolan + $this->bledyDecyzji + $this->bledyZgloszen;
    }

    /**
     * Kandydaci tego przebiegu: skasowani, nieudani i decyzje zatrzymane
     * żywym odwołaniem (te ostatnie to NIE błąd — tylko część puli).
     */
    public function kandydaci(): int
    {
        return $this->usunieteOdwolania + $this->usunieteDecyzje + $this->usunieteZgloszenia
            + $this->bledyLacznie() + $this->pominieteDecyzjeZywymOdwolaniem;
    }

    /**
     * Częściowa porażka to porażka (#1534, wzorem #1342) — komenda zwraca
     * wtedy kod ≠ 0, a `Harmonogram::artisan()` zamienia go w wyjątek.
     */
    public function czesciowaPorazka(): bool
    {
        return $this->bledyLacznie() > 0;
    }
}
