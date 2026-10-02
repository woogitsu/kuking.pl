<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Zakupy\ListaZakupow;
use Illuminate\Console\Command;

/**
 * Retencja migawek „Cofnij usunięcie” z listy zakupów (#2630): po
 * `kuking.zakupy.cofniecie_minut` minutach od usunięcia migawka znika z bazy.
 * Cofnięcie odrzuca wygasłą migawkę także bez tego zadania; ono dba o to, żeby
 * kopia pozycji nie leżała dłużej, gdy osoba już na listę nie wraca.
 */
class SprzatajCofnieciaZakupow extends Command
{
    protected $signature = 'kuking:sprzataj-cofniecia-zakupow';

    protected $description = 'Kasuje wygasłe migawki usuniętych pozycji listy zakupów (cofnięcie usunięcia, #2630).';

    public function handle(ListaZakupow $lista): int
    {
        $this->info('Skasowano wygasłych migawek cofnięcia: '.$lista->sprzatnijWygasleCofniecia().'.');

        return self::SUCCESS;
    }
}
