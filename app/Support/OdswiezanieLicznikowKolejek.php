<?php

declare(strict_types=1);

namespace App\Support;

/**
 * „Coś w kolejkach panelu zniknęło albo się pojawiło — odśwież liczniki."
 *
 * KONTRAKT PO STRONIE WOŁAJĄCEGO (#2149, etap 3). Retencja spraw
 * moderacyjnych (`App\Domain\Compliance\PrzedawnioneSprawyModeracyjne`) kasuje
 * zbiorczo, więc zdarzenia modeli nie odświeżają liczników i musi zrobić to
 * sama — raz, na koniec przebiegu. Liczniki liczy jednak `Moderation`
 * (`KolejkiPanelu`), a `Moderation` przez `Security` i `Users` zawraca do
 * `Compliance`. Bezpośredni import `KolejkiPanelu` zamykał ten pierścień.
 *
 * Kontrakt jest w `App\Support` (nie w `Compliance`), żeby `Moderation`
 * nie musiało importować `Compliance` tylko po to, by go zaimplementować.
 * Implementuje go `KolejkiPanelu`, a wiąże `AppServiceProvider` — jest to
 * TEN SAM singleton, który obsługuje zdarzenia modeli (flaga „już
 * zaplanowane na commit" jest wspólna). Pilnuje tego
 * `GrafModulowDomenyBezCykliTest`.
 */
interface OdswiezanieLicznikowKolejek
{
    /** Przelicz tanie liczby po commicie, najwyżej raz na transakcję. */
    public function odswiez(): void;
}
