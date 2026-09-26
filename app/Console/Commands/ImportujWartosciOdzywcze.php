<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze as Import;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Wczytuje tabelę wartości odżywczych z `database/data/odzywcze/` (D-299).
 *
 * Uruchamiana ręcznie po wdrożeniu, które zmienia pliki danych, i raz po
 * pierwszym wdrożeniu tej funkcji. Idempotentna — drugie uruchomienie na
 * tych samych plikach niczego nie zmienia. Niczego nie pobiera z sieci.
 */
class ImportujWartosciOdzywcze extends Command
{
    protected $signature = 'kuking:importuj-wartosci-odzywcze';

    protected $description = 'Wczytuje tabelę wartości odżywczych (CIQUAL/USDA) i miary domowe z plików w repozytorium';

    public function handle(Import $import): int
    {
        try {
            $wynik = $import->handle();
        } catch (RuntimeException $e) {
            $this->error('Nie wczytano niczego: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wczytano: %d składników, %d nazw, %d miar domowych. Usunięte pozycje, których nie ma już w pliku: %d.',
            $wynik['skladniki'],
            $wynik['aliasy'],
            $wynik['miary'],
            $wynik['usuniete'],
        ));

        return self::SUCCESS;
    }
}
