<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\PrzedawnioneImporty;
use Illuminate\Console\Command;

/**
 * Retencja zleceń odczytu przepisu (D-298): surowa odpowiedź modelu 30 dni,
 * wiersz 90 dni. Reguła i wyjątek: `App\Domain\Import\PrzedawnioneImporty`.
 */
class SprzatajImporty extends Command
{
    protected $signature = 'kuking:sprzataj-importy
                            {--na-sucho : Policz, ale niczego nie zmieniaj}';

    protected $description = 'Czyści surowe odpowiedzi modelu po 30 dniach i zlecenia odczytu przepisu po 90 dniach (D-298).';

    public function handle(PrzedawnioneImporty $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $wynik = $sprzataj->posprzataj($naSucho);

        $this->info(($naSucho ? 'Do wyczyszczenia' : 'Wyczyszczono').": {$wynik['odpowiedzi']} odpowiedzi modelu; "
            .($naSucho ? 'do skasowania' : 'skasowano').": {$wynik['wiersze']} zleceń odczytu i {$wynik['rezerwacje']} zamkniętych rezerwacji budżetu.");

        return self::SUCCESS;
    }
}
