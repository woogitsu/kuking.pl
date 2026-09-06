<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\OsieroconeZdjecia;
use Illuminate\Console\Command;

class SprzatajOsieroconeZdjecia extends Command
{
    protected $signature = 'kuking:sprzataj-osierocone-zdjecia
                            {--godziny=24 : Ile godzin zdjęcie ma być nieprzypięte, zanim je skasujemy}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje zdjęcia wgrane, ale do niczego nieprzypięte (audyt C1).';

    public function handle(): int
    {
        $godziny = max(1, (int) $this->option('godziny'));
        $naSucho = (bool) $this->option('na-sucho');

        $ile = (new OsieroconeZdjecia($godziny))->posprzataj($naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} zdjęć nieprzypiętych od co najmniej {$godziny} h."
            : "Skasowano {$ile} zdjęć nieprzypiętych od co najmniej {$godziny} h.");

        return self::SUCCESS;
    }
}
