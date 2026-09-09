<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneZmianyAdresu;
use Illuminate\Console\Command;

/**
 * Sprzątanie wygasłych żądań zmiany adresu e-mail (issue #195).
 *
 * Termin każdego żądania stoi w jego własnej kolumnie `expires_at`
 * (`config('kuking.account.email_change_ttl_hours')` godzin od zamówienia),
 * więc ta komenda nie ma żadnego progu do ustawiania — kasuje to, co
 * przeterminowane. Pełne uzasadnienie: `PrzedawnioneZmianyAdresu`.
 */
class SprzatajZmianyAdresu extends Command
{
    protected $signature = 'kuking:sprzataj-zmiany-adresu
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wygasłe żądania zmiany adresu e-mail (issue #195).';

    public function handle(PrzedawnioneZmianyAdresu $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        $ile = $sprzataj->posprzataj($naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} wygasłych żądań zmiany adresu e-mail."
            : "Skasowano {$ile} wygasłych żądań zmiany adresu e-mail.");

        return self::SUCCESS;
    }
}
