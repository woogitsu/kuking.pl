<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\PrzedawnioneSygnaly;
use Illuminate\Console\Command;

/**
 * Retencja 90 dni dla `product_signals` (issue #115) — minimalizacja danych,
 * nie trzymamy telemetrii dłużej, niż jest do czegokolwiek potrzebna
 * (AGENTS.md §7).
 */
class SprzatajSygnaly extends Command
{
    protected $signature = 'kuking:sprzataj-sygnaly
                            {--dni= : Ile dni trzymać sygnał, zanim go skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wiersze product_signals starsze niż okres retencji (issue #115).';

    public function handle(PrzedawnioneSygnaly $sprzataj): int
    {
        $dni = $this->option('dni') !== null
            ? max(1, (int) $this->option('dni'))
            : (int) config('kuking.analytics.signal_retention_days');

        $naSucho = (bool) $this->option('na-sucho');

        $ile = $sprzataj->posprzataj($dni, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} sygnałów starszych niż {$dni} dni."
            : "Skasowano {$ile} sygnałów starszych niż {$dni} dni.");

        return self::SUCCESS;
    }
}
