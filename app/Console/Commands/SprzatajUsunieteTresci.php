<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use Illuminate\Console\Command;

/**
 * Twarde usunięcie wpisów, przepisów i komentarzy skasowanych przez autora
 * (audyt B5, znalezisko 1). Reguły i wyjątki opisuje
 * `App\Domain\Compliance\PrzedawnioneUsunieteTresci`.
 */
class SprzatajUsunieteTresci extends Command
{
    protected $signature = 'kuking:sprzataj-usuniete-tresci
                            {--dni= : Ile dni od usunięcia trzymać treść (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje na stałe treści usunięte przez autora po okresie z konfiguracji, razem ze zdjęciami (audyt B5).';

    public function handle(PrzedawnioneUsunieteTresci $sprzataj): int
    {
        $dni = $this->option('dni') !== null
            ? max(1, (int) $this->option('dni'))
            : (int) config('kuking.usuniete_tresci.retention_days');

        $naSucho = (bool) $this->option('na-sucho');

        $w = $sprzataj->posprzataj($dni, $naSucho);

        $this->info(($naSucho ? 'Do skasowania' : 'Skasowano')
            ." (usunięte ponad {$w['dni']} dni temu): wpisy {$w['wpisy']}, przepisy {$w['przepisy']}, "
            ."przepisy opróżnione do nagrobka {$w['nagrobki']}, komentarze {$w['komentarze']}"
            .($naSucho ? '.' : ", zdjęcia {$w['zdjecia']}."));

        return self::SUCCESS;
    }
}
