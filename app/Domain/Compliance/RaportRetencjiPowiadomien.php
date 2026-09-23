<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

/**
 * Wynik jednego przebiegu {@see PrzedawnionePowiadomienia::posprzataj()}.
 *
 * Dwie ścieżki w jednym raporcie — zwykłe powiadomienia (Wzorzec B, masowy
 * `DELETE` wg jednego progu) i powiadomienia moderacyjne (własny termin,
 * `Notification::terminOchronyOdwolawczej()`) — bo od issue #19/ADR §5.2
 * i §5.6 to już nie jest jedna liczba dla całej tabeli. Bez tego rozbicia
 * nikt by nie zauważył, że automat np. przestał w ogóle rozpoznawać
 * powiadomienia moderacyjne i kasuje je jak zwykłe.
 */
final class RaportRetencjiPowiadomien
{
    public function __construct(
        public readonly int $usunieteZwykle,
        public readonly int $usunieteModeracyjne,
        public readonly int $zatrzymaneTerminemOdwolania,
        public readonly int $bezPowiazanejDecyzji,
        /**
         * Kandydaci do skasowania, których `delete()` rzucił wyjątek (#1342).
         * Coś innego niż `bezPowiazanejDecyzji`: tamte pominęliśmy świadomie,
         * te próbowaliśmy skasować i się nie udało. W trybie na sucho zawsze 0.
         */
        public readonly int $nieudaneModeracyjne = 0,
    ) {}

    /** Przebieg zostawił w bazie wiersze po terminie, które miał skasować. */
    public function czesciowaPorazka(): bool
    {
        return $this->nieudaneModeracyjne > 0;
    }

    public function usunieteLacznie(): int
    {
        return $this->usunieteZwykle + $this->usunieteModeracyjne;
    }
}
