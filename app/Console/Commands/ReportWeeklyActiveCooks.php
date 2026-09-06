<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\WeeklyActiveCooks;
use Illuminate\Console\Command;

/**
 * Weekly Active Cooks (WAC), liczone z Postgresa (issue #114).
 *
 * `docs/seo/ANALYTICS.md` §1.3-1.4 (referencja do brakującego w repozytorium
 * `docs/research/ANALITYKA.md`) znalazła lukę w zapytaniu z §2.2: liczyło
 * WSZYSTKICH, łącznie z kontem gospodarza, kontami zbanowanymi/
 * `pending_delete` i kontami testowymi. Przy 20-50 kontach zamkniętej alfy
 * gospodarz jako gwarantowany, cotygodniowy wpis jest zauważalnym
 * zniekształceniem liczby, którą zespół czyta jako dowód sukcesu.
 *
 * Cała reguła „kto się liczy" mieszka w `App\Domain\Analytics` —
 * ta komenda tylko formatuje wynik.
 */
class ReportWeeklyActiveCooks extends Command
{
    protected $signature = 'kuking:wac
                            {--tygodnie= : Ile ostatnich tygodni pokazać (domyślnie wszystkie z aktywnością)}';

    protected $description = 'Liczy Weekly Active Cooks tygodniowo, z wykluczeniem gospodarza, kont zbanowanych/kasowanych i testowych';

    public function handle(WeeklyActiveCooks $wac): int
    {
        $opcja = $this->option('tygodnie');
        $ileTygodni = $opcja === null ? null : max(1, (int) $opcja);

        $tygodnie = $wac->weekly($ileTygodni);

        if ($tygodnie->isEmpty()) {
            $this->info('Brak aktywności kwalifikującej się do WAC w wybranym okresie.');

            return self::SUCCESS;
        }

        $this->info('Weekly Active Cooks (WAC) — tydzień od–do i liczba:');

        foreach ($tygodnie as $tydzien) {
            $this->line("{$tydzien->week_start} – {$tydzien->week_end}: {$tydzien->weekly_active_cooks}");
        }

        return self::SUCCESS;
    }
}
