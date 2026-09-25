<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\DziennikWymazan;
use Illuminate\Console\Command;

/**
 * Nocna pielęgnacja dziennika wymazań spoza bazy (audyt B5, znalezisko 3):
 * dopisuje brakujące wpisy kont wymazanych w oknie kopii i kasuje wpisy
 * starsze niż najstarsza kopia, z której konto mogłoby wrócić.
 */
class PielegnujDziennikWymazan extends Command
{
    protected $signature = 'kuking:dziennik-wymazan';

    protected $description = 'Uzupełnia i przycina dziennik wymazań kont trzymany poza bazą (audyt B5).';

    public function handle(DziennikWymazan $dziennik): int
    {
        $dni = (int) config('kuking.dziennik_wymazan.retention_days');

        $dopisane = $dziennik->uzupelnij($dni);
        $skasowane = $dziennik->przytnij($dni);

        $this->info("Dziennik wymazań: dopisano {$dopisane}, skasowano starszych niż {$dni} dni: {$skasowane}.");

        return self::SUCCESS;
    }
}
