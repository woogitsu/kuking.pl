<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneDowodyFacebooka;
use App\Support\Odmiana;
use Illuminate\Console\Command;

/**
 * Sprzątanie wygasłych dowodów połączenia z Facebookiem (issue #2319).
 *
 * Termin każdego dowodu stoi w jego kolumnie `expires_at` (dziesięć minut
 * od prośby), więc komenda nie ma progu do ustawiania. Uzasadnienie:
 * `PrzedawnioneDowodyFacebooka`.
 */
class SprzatajDowodyFacebooka extends Command
{
    protected $signature = 'kuking:sprzataj-dowody-facebooka
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wygasłe dowody połączenia konta z Facebookiem (issue #2319).';

    public function handle(PrzedawnioneDowodyFacebooka $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        $ile = $sprzataj->posprzataj($naSucho);

        $dowody = $ile.' '.Odmiana::rzeczownik($ile, 'wygasły dowód', 'wygasłe dowody', 'wygasłych dowodów');

        $this->info($naSucho
            ? "Do skasowania: {$dowody} połączenia z Facebookiem."
            : "Skasowano {$dowody} połączenia z Facebookiem.");

        return self::SUCCESS;
    }
}
