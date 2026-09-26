<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze as Import;
use App\Exceptions\BladDlaCzlowieka;
use Illuminate\Console\Command;

/**
 * Wczytuje tabelę wartości odżywczych z `database/data/odzywcze/` (D-299).
 *
 * Od #1961 stoi w `preDeployCommand` obok migracji i `db:seed` — leci przy
 * KAŻDYM wdrożeniu, nie tylko ręcznie. Idempotentna i szybka do powtórzenia:
 * gdy pliki danych mają ten sam hash, co przy poprzednim udanym imporcie,
 * a tabela już ma dane, komenda niczego nie parsuje ani nie zapisuje —
 * `--wymus` wymusza pełny import mimo to. Niczego nie pobiera z sieci.
 */
class ImportujWartosciOdzywcze extends Command
{
    protected $signature = 'kuking:importuj-wartosci-odzywcze
                            {--wymus : Wykonaj import, nawet gdy pliki mają ten sam hash co ostatni udany import}';

    protected $description = 'Wczytuje tabelę wartości odżywczych (CIQUAL/USDA) i miary domowe z plików w repozytorium';

    public function handle(Import $import): int
    {
        try {
            $wynik = $import->handle(wymus: (bool) $this->option('wymus'));
        } catch (BladDlaCzlowieka $e) {
            $this->error('Nie wczytano niczego: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($wynik['pominieto']) {
            $this->info(sprintf(
                'Pliki danych są w tej samej wersji co poprzedni import — pominięto. W bazie: %d składników, %d nazw, %d miar domowych. Użyj --wymus, żeby wymusić ponowny import.',
                $wynik['skladniki'],
                $wynik['aliasy'],
                $wynik['miary'],
            ));

            return self::SUCCESS;
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
