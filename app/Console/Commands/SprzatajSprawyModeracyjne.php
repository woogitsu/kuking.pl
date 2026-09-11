<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneSprawyModeracyjne;
use App\Support\Odmiana;
use Illuminate\Console\Command;

/**
 * Retencja SPRAWY MODERACYJNEJ — `appeals` + `moderation_actions` + `reports`
 * (issue #19, docs/decyzje/ADR_RETENCJE.md §4, §5.3-5.5).
 *
 * DECYZJA WŁAŚCICIELA (2026-09-07): `config('kuking.moderation.case_retention_months')`
 * miesięcy (domyślnie 36, art. 442¹ k.c.) od ZAMKNIĘCIA sprawy — osobno dla
 * każdej z trzech tabel (`appeals.decided_at`, `moderation_actions.created_at`,
 * `reports.resolved_at`). Sprawy otwarte NIGDY nie są kandydatem.
 *
 * KOLEJNOŚĆ WEWNĄTRZ JEDNEGO PRZEBIEGU JEST ZNACZĄCA: appeals → moderation_actions
 * → reports. Patrz `App\Domain\Compliance\PrzedawnioneSprawyModeracyjne` po
 * pełne uzasadnienie (kaskada `appeals.moderation_action_id` ma
 * `cascadeOnDelete` — kasowanie decyzji przed jej odwołaniem zabrałoby
 * odwołanie, zanim minął jego własny czas).
 */
class SprzatajSprawyModeracyjne extends Command
{
    protected $signature = 'kuking:sprzataj-sprawy-moderacyjne
                            {--miesiace= : Ile miesięcy od zamknięcia sprawy trzymać wiersz, zanim go skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje zamknięte sprawy moderacyjne (zgłoszenia, decyzje, odwołania) starsze niż okres retencji (issue #19).';

    public function handle(PrzedawnioneSprawyModeracyjne $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.moderation.case_retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $raport = $sprzataj->posprzataj($miesiace, $naSucho);

        $czasownik = $naSucho ? 'Do skasowania' : 'Skasowano';

        $this->info("Próg: sprawy zamknięte wcześniej niż {$miesiace} miesięcy temu.");
        $this->line("{$czasownik} odwołań (appeals): {$raport->usunieteOdwolania}.");
        $this->line("{$czasownik} decyzji moderacyjnych (moderation_actions): {$raport->usunieteDecyzje}.");
        $this->line("Pominięto decyzji moderacyjnych z powodu żywego odwołania: {$raport->pominieteDecyzjeZywymOdwolaniem}.");
        $this->line("{$czasownik} zgłoszeń (reports): {$raport->usunieteZgloszenia}.");

        $bledyLacznie = $raport->bledyOdwolan + $raport->bledyDecyzji + $raport->bledyZgloszen;
        $wierszy = Odmiana::rzeczownik($bledyLacznie, 'wiersza', 'wierszy', 'wierszy');

        if ($bledyLacznie > 0) {
            // `warn`, nie `line`: to musi być widoczne w logu harmonogramu.
            $this->warn(
                "Nie udało się skasować {$bledyLacznie} {$wierszy} (odwołania: {$raport->bledyOdwolan}, "
                ."decyzje: {$raport->bledyDecyzji}, zgłoszenia: {$raport->bledyZgloszen}) — szczegóły w logu, "
                .'następny przebieg spróbuje ponownie.',
            );
        }

        return self::SUCCESS;
    }
}
