<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Models\CookingProgress;
use Illuminate\Console\Command;

/**
 * Sprzątanie wygasłego, zapamiętanego na koncie postępu gotowania (#2016).
 *
 * Termin każdego wiersza stoi w jego własnej kolumnie `expires_at`
 * (`config('kuking.cooking_progress.retention_hours')` godzin od ostatniej
 * zmiany), więc komenda nie ma progu do ustawiania — kasuje to, co
 * przeterminowane. Odczyt i tak ignoruje wygasłe wiersze, więc to sprzątanie
 * jest higieną danych (minimalizacja, RODO), nie warunkiem poprawności.
 */
class SprzatajPostepGotowania extends Command
{
    protected $signature = 'kuking:sprzataj-postep-gotowania
                            {--na-sucho : Policz, ale niczego nie kasuj}
                            {--wszystkie : Skasuj TAKŻE niewygasły postęp — tylko do wycofania migracji}';

    protected $description = 'Kasuje wygasły, zapamiętany na koncie postęp gotowania (#2016).';

    public function handle(PostepGotowania $postep): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $wszystkie = (bool) $this->option('wszystkie');

        if ($wszystkie && ! $naSucho) {
            $zywe = CookingProgress::query()->where('expires_at', '>', now())->count();

            // Każdy żywy wiersz to ktoś, kto gotuje teraz i po skasowaniu
            // zobaczy przepis bez odhaczeń — pytamy i mówimy, ilu osób to dotyczy.
            if ($zywe > 0 && ! $this->confirm(
                "Liczba osób, które teraz gotują z zapamiętanym postępem: {$zywe}. Po skasowaniu zobaczą swoje przepisy bez odhaczeń. Kasować?",
                default: false,
            )) {
                $this->warn('Nic nie skasowano.');

                return self::FAILURE;
            }
        }

        $ile = $postep->posprzataj($naSucho, $wszystkie);

        $this->info($naSucho
            ? "Do skasowania: {$ile} zapamiętanych postępów gotowania."
            : "Skasowano {$ile} zapamiętanych postępów gotowania.");

        return self::SUCCESS;
    }
}
