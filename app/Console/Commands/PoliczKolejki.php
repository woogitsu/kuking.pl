<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\KolejkiPanelu;
use Illuminate\Console\Command;

/**
 * Przelicza liczniki przy pozycjach panelu moderacji i zapisuje je w cache.
 *
 * DLACZEGO OSOBNA KOMENDA, A NIE PRZELICZANIE W WIDOKU: menu boczne stoi na
 * każdej stronie panelu, a pięć `COUNT(*)` na odsłonę jest dokładnie tym,
 * czego ta komenda ma nie dopuścić — pełne uzasadnienie w
 * `App\Domain\Moderation\KolejkiPanelu`. Ten sam wzorzec co
 * `kuking:policz-kukingow` dla licznika w stopce.
 *
 * Bieżącej świeżości pilnują zdarzenia modeli (`AppServiceProvider`); ta
 * komenda jest siatką bezpieczeństwa — po wdrożeniu cache jest pusty
 * i bez niej liczniki nie pojawiłyby się aż do pierwszego zapisu.
 */
class PoliczKolejki extends Command
{
    protected $signature = 'kuking:policz-kolejki';

    protected $description = 'Przelicza liczniki kolejek panelu moderacji i zapisuje je w cache';

    public function handle(KolejkiPanelu $kolejki): int
    {
        $liczby = $kolejki->przelicz();

        foreach ($liczby as $nazwa => $ile) {
            $this->line($nazwa.': '.$ile);
        }

        return self::SUCCESS;
    }
}
