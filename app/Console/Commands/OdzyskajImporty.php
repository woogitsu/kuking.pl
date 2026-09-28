<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\OdzyskanieImportow;
use Illuminate\Console\Command;

/**
 * Domyka porzucone rezerwacje budżetu modelu i zlecenia odczytu przepisu,
 * których nikt już nie wykona (D-298 „maszyna stanów”, #1973, #1977).
 * Reguła: `App\Domain\Import\OdzyskanieImportow`.
 */
class OdzyskajImporty extends Command
{
    protected $signature = 'kuking:odzyskaj-importy';

    protected $description = 'Zwalnia porzucone rezerwacje budżetu modelu i kończy jawnym błędem zlecenia odczytu, których zadanie zginęło (D-298).';

    public function handle(OdzyskanieImportow $odzyskanie): int
    {
        $wynik = $odzyskanie->odzyskaj();

        $this->info("Domknięte rezerwacje (zlecenia): {$wynik['rezerwacje']}; porzucone zlecenia zakończone błędem: {$wynik['zlecenia']}.");

        return self::SUCCESS;
    }
}
