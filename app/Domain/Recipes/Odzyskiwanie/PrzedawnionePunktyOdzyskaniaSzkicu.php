<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzyskiwanie;

use Illuminate\Support\Facades\DB;

/**
 * Nocne sprzątanie punktów odzyskania tekstu szkiców (#2512).
 *
 * Kasuje punkty starsze niż `kuking.przepisy.szkic_punkt_odzyskania_dni` oraz
 * punkty szkiców, które przestały być szkicami (opublikowane albo usunięte
 * miękko). Wołane przez `kuking:sprzataj-usuniete-tresci`. Wymazanie konta
 * kasuje punkty wcześniej (`EraseAccountData`); usunięcie szkicu na stałe
 * kasuje je kluczem obcym.
 *
 * Zakres kasowania to wyłącznie te wiersze — nigdy przepis, zdjęcie ani wersja.
 */
final class PrzedawnionePunktyOdzyskaniaSzkicu
{
    public const BUDZET_PRZEBIEGU = 1000;

    /** @return int ile punktów skasowano (przy `$naSucho` — ile by skasowano) */
    public function posprzataj(bool $naSucho = false): int
    {
        $prog = now()->subDays(max(1, (int) config('kuking.przepisy.szkic_punkt_odzyskania_dni')));

        $kandydaci = DB::table('draft_restore_points as p')
            ->leftJoin('recipes as r', 'r.id', '=', 'p.recipe_id')
            ->where(function ($q) use ($prog): void {
                $q->where('p.taken_at', '<', $prog)
                    ->orWhereNull('r.id')
                    ->orWhereNotNull('r.deleted_at')
                    ->orWhere('r.status', '<>', 'draft')
                    ->orWhereNotNull('r.published_at');
            })
            ->orderBy('p.taken_at')
            ->limit(self::BUDZET_PRZEBIEGU)
            ->pluck('p.id');

        if ($naSucho || $kandydaci->isEmpty()) {
            return $kandydaci->count();
        }

        // Wybór ID nie blokuje przywrócenia. Warunek DELETE musi ponownie
        // ocenić bieżące punkty po ewentualnym oczekiwaniu na ich UPDATE.
        return DB::table('draft_restore_points')
            ->whereIn('id', $kandydaci->all())
            ->where(function ($q) use ($prog): void {
                $q->where('taken_at', '<', $prog)
                    ->orWhereNotExists(function ($q): void {
                        $q->selectRaw('1')
                            ->from('recipes as r')
                            ->whereColumn('r.id', 'draft_restore_points.recipe_id')
                            ->whereNull('r.deleted_at')
                            ->where('r.status', 'draft')
                            ->whereNull('r.published_at');
                    });
            })
            ->delete();
    }
}
