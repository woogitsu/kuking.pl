<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

/**
 * Numer układu `dane.json` w paczce eksportu (issue #1985).
 *
 * Eksport wpisuje go do `o_tym_pliku.wersja_formatu`, a podgląd importu
 * (`App\Domain\Users\Import\PodgladPaczkiEksportu`) odrzuca paczkę NOWSZĄ niż
 * ta, którą sam rozumie — zamiast zgadywać znaczenie pól. Podnosimy numer
 * wtedy, gdy zmienia się znaczenie albo nazwa pola, które importer czyta
 * (`przepisy`, `wpisy`, `kolekcje`); samo dopisanie nowej sekcji go nie rusza.
 *
 * Paczki zamówione przed tym polem numeru nie mają; importer traktuje je jak
 * wersję 1, bo układ przepisów, wpisów i zeszytów się od tamtej pory nie zmienił.
 */
final class WersjaFormatuPaczki
{
    public const AKTUALNA = 1;

    /** Numer, który importer przypisuje paczce bez pola `wersja_formatu`. */
    public const SPRZED_NUMEROWANIA = 1;
}
