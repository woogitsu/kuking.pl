<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wspólna blokada zimnego budowania i modelowego unieważnienia kanału.
 * Transakcja trzyma blokadę doradczą aż PO zapisie cache; model bierze tę
 * samą blokadę dopiero po commicie zmiany treści. Spóźniony budowniczy może
 * więc oddać dawną odpowiedź, ale nie zostawi jej kolejnym żądaniom.
 */
final class CacheKanalu
{
    /** @param Closure(): string $zbuduj */
    public static function zapamietaj(string $klucz, int $sekundy, Closure $zbuduj): string
    {
        $gotowe = Cache::get($klucz);
        if (is_string($gotowe)) {
            return $gotowe;
        }

        return DB::transaction(static function () use ($klucz, $sekundy, $zbuduj): string {
            self::zablokuj($klucz);

            return Cache::remember($klucz, now()->addSeconds($sekundy), $zbuduj);
        });
    }

    public static function zapomnij(string $klucz): void
    {
        DB::transaction(static function () use ($klucz): void {
            self::zablokuj($klucz);
            Cache::forget($klucz);
        });
    }

    private static function zablokuj(string $klucz): void
    {
        // Stała odróżnia te blokady od pozostałych par kluczy doradczych.
        // Kolizja hashtext najwyżej serializuje dwa kanały, nie miesza treści.
        DB::selectOne('SELECT pg_advisory_xact_lock(2863, hashtext(?))', [$klucz]);
    }
}
