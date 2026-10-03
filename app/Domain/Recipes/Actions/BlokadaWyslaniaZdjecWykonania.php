<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\CookedEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sesyjna blokada jednego wysłania obejmuje również zapis pliku przed transakcją
 * dołączenia. Nie zastępuje blokad media → users → cooked_events w tej transakcji.
 */
final class BlokadaWyslaniaZdjecWykonania
{
    /**
     * @template T
     *
     * @param  callable(): T  $czynność
     * @return T
     */
    public function wykonaj(User $kucharz, CookedEvent $wykonanie, string $klucz, callable $czynność): mixed
    {
        // Kolizja hashtext najwyżej ustawia dwa różne wysłania w kolejce.
        $zasob = (string) $kucharz->getKey().':'.(string) $wykonanie->getKey().':'.$klucz;
        $polaczenie = DB::connection();
        $polaczenie->select('SELECT pg_advisory_lock(2811, hashtext(?))', [$zasob]);

        try {
            return $czynność();
        } finally {
            $polaczenie->select('SELECT pg_advisory_unlock(2811, hashtext(?))', [$zasob]);
        }
    }
}
