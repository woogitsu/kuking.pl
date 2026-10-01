<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\Gotowanie\Wspolne\SprzatanieWspolnegoGotowania;
use App\Models\CookingSession;
use Illuminate\Console\Command;

/**
 * Sprzątanie wygasłych sesji wspólnego gotowania (#2385). Sesja żyje
 * `kuking.wspolne_gotowanie.retencja_godziny` od założenia; komenda kasuje to,
 * co przeterminowane, razem z odhaczeniami, pomocnikami i zaproszeniami.
 */
class SprzatajWspolneGotowanie extends Command
{
    protected $signature = 'kuking:sprzataj-wspolne-gotowanie
                            {--na-sucho : Policz, ale niczego nie kasuj}
                            {--wszystkie : Skasuj TAKŻE trwające sesje — tylko do wycofania migracji}';

    protected $description = 'Kasuje wygasłe sesje wspólnego gotowania (#2385).';

    public function handle(SprzatanieWspolnegoGotowania $sprzatanie): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $wszystkie = (bool) $this->option('wszystkie');

        if ($wszystkie && ! $naSucho) {
            $zywe = CookingSession::query()->where('expires_at', '>', now())->count();

            if ($zywe > 0 && ! $this->confirm(
                "Liczba trwających wspólnych gotowań: {$zywe}. Po skasowaniu uczestnicy stracą wspólny postęp. Kasować?",
                default: false,
            )) {
                $this->warn('Nic nie skasowano.');

                return self::FAILURE;
            }
        }

        $ile = $sprzatanie->posprzataj($naSucho, $wszystkie);

        $this->info($naSucho
            ? "Do skasowania: {$ile} sesji wspólnego gotowania."
            : "Skasowano {$ile} sesji wspólnego gotowania.");

        return self::SUCCESS;
    }
}
