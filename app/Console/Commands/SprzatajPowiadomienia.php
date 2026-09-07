<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use Illuminate\Console\Command;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2):
 * `config('kuking.notifications.retention_months')` miesięcy od `created_at`,
 * niezależnie od `read_at` (jeden wiek dla wszystkich — wariant A z ADR §6).
 */
class SprzatajPowiadomienia extends Command
{
    protected $signature = 'kuking:sprzataj-powiadomienia
                            {--miesiace= : Ile miesięcy trzymać powiadomienie, zanim je skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje powiadomienia starsze niż okres retencji, niezależnie od tego, czy zostały przeczytane (issue #19).';

    public function handle(PrzedawnionePowiadomienia $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.notifications.retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $ile = $sprzataj->posprzataj($miesiace, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} powiadomień starszych niż {$miesiace} miesięcy."
            : "Skasowano {$ile} powiadomień starszych niż {$miesiace} miesięcy.");

        return self::SUCCESS;
    }
}
