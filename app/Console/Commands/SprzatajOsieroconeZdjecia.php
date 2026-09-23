<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\OsieroconeZdjecia;
use App\Support\Odmiana;
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

        // Odmienia się i rzeczownik, i przymiotnik po nim — dlatego cała
        // fraza, a nie samo „zdjęcie": „1 zdjęcie nieprzypięte",
        // „2 zdjęcia nieprzypięte", „5 zdjęć nieprzypiętych".
        $zdjecia = Odmiana::rzeczownik(
            $ile,
            'zdjęcie nieprzypięte',
            'zdjęcia nieprzypięte',
            'zdjęć nieprzypiętych',
        );

        $this->info($naSucho
            ? "Do skasowania: {$ile} {$zdjecia} od co najmniej {$godziny} h."
            : "Skasowano {$ile} {$zdjecia} od co najmniej {$godziny} h.");

        return self::SUCCESS;
    }
}
