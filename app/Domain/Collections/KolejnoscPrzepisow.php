<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ręczna kolejność przepisów w zeszycie (#2544) — JEDNO miejsce na jej reguły.
 *
 * REGUŁY (te same czyta ekran zeszytu, wydruk i eksport)
 *  - `collection_items.position` `NULL` = zeszyt nieułożony: kolejność jak
 *    dotąd, od najnowszego zapisu. Ręczny porządek powstaje z pierwszego
 *    świadomego kliknięcia przy przepisie i obejmuje wtedy WSZYSTKIE przepisy
 *    zeszytu, także te niewidoczne dla oglądającego (jedna lista, bez dziur
 *    w logice; niewidoczny przepis nie zdradza się niczym poza numerem
 *    w miejscu, którego nikt nie zobaczy).
 *  - Przepis dopisany albo przywrócony do ułożonego zeszytu staje NA KOŃCU
 *    (`nastepnaPozycja()`); do nieułożonego — jak zawsze, na początku listy
 *    „od najnowszego".
 *  - Wyjęcie przepisu zostawia dziurę w numerach i to jest w porządku:
 *    przesuwanie liczy się po kolejności, nie po różnicy numerów.
 *  - Przesunięcie nie rusza `created_at`, notatki ani niczego, co czyta sekcja
 *    „Ostatnio zapisane", i nikogo nie powiadamia.
 *
 * KTO MOŻE UKŁADAĆ: `CollectionPolicy::reorder()`. Kolejność WIDZĄ wszyscy,
 * którzy mogą oglądać zeszyt — ułożenie nie jest osobnym uprawnieniem do
 * czytania.
 */
final class KolejnoscPrzepisow
{
    /** Ile przepisów na stronie zeszytu — z niej liczymy, na którą stronę wrócić. */
    public const NA_STRONE = 12;

    /**
     * Pozycja dla przepisu dopisywanego TERAZ: `null`, gdy zeszyt jest
     * nieułożony (kolejność zostaje „od najnowszego"), albo następny numer
     * po największym.
     *
     * Wołać pod zamkiem zeszytu (`ZamekZapisuDoZeszytu`), inaczej dwa równoległe
     * zapisy dostałyby ten sam numer i drugi odbiłby się o unikalny indeks.
     */
    public static function nastepnaPozycja(Collection|string $zeszyt): ?int
    {
        $maks = DB::table('collection_items')
            ->where('collection_id', self::id($zeszyt))
            ->whereNotNull('recipe_id')
            ->max('position');

        return $maks === null ? null : (int) $maks + 1;
    }

    /** Czy ten zeszyt ma ręcznie ułożoną kolejność przepisów. */
    public static function jestUlozony(Collection|string $zeszyt): bool
    {
        return DB::table('collection_items')
            ->where('collection_id', self::id($zeszyt))
            ->whereNotNull('position')
            ->exists();
    }

    /**
     * Wszystkie przepisy zeszytu w kolejności, w jakiej je pokazuje ekran
     * (widoczne i niewidoczne razem) — te same trzy klucze co
     * `Collection::recipes()`.
     *
     * @return list<string> identyfikatory przepisów
     */
    public static function uklad(Collection|string $zeszyt): array
    {
        return DB::table('collection_items')
            ->where('collection_id', self::id($zeszyt))
            ->whereNotNull('recipe_id')
            ->orderByRaw('position ASC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('recipe_id')
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Odcisk układu, który człowiek widział — formularz niesie go do serwera,
     * a serwer porównuje pod zamkiem z tym, co leży w bazie. Zależy od samej
     * kolejności, więc pierwsze ułożenie (nadanie numerów w kolejności, którą
     * człowiek już widział) go nie zmienia.
     *
     * @param  list<string>  $uklad
     */
    public static function odcisk(array $uklad): string
    {
        return hash('sha256', implode(',', $uklad));
    }

    private static function id(Collection|string $zeszyt): string
    {
        return $zeszyt instanceof Collection ? (string) $zeszyt->getKey() : $zeszyt;
    }
}
