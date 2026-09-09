<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\LiczbaKukingow;
use Illuminate\Console\Command;

/**
 * Przelicza licznik społeczności do stopki (issue #38) i zapisuje go
 * w cache — patrz uzasadnienie „DLACZEGO TO NIE JEST Cache::remember()"
 * w `App\Domain\Analytics\LiczbaKukingow`: to musi być OSOBNA komenda,
 * wołana z harmonogramu, a nie policzone przy okazji jakiegoś żądania,
 * bo stopka jest na KAŻDEJ stronie serwisu.
 *
 * Cała reguła „kto się liczy" mieszka w `App\Domain\Analytics` — ta
 * komenda tylko woła przeliczenie i wypisuje wynik (ten sam wzorzec co
 * `ReportWeeklyActiveCooks`).
 */
class PoliczKukingow extends Command
{
    protected $signature = 'kuking:policz-kukingow';

    protected $description = 'Przelicza licznik społeczności („N kuKINGów" w stopce) i zapisuje w cache';

    public function handle(LiczbaKukingow $licznik): int
    {
        $liczba = $licznik->przelicz();

        $this->info("Licznik społeczności: {$liczba}.");

        return self::SUCCESS;
    }
}
