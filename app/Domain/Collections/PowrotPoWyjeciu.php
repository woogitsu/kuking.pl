<?php

declare(strict_types=1);

namespace App\Domain\Collections;

/**
 * Wspólne reguły „drogi powrotu" po wyjęciu z zeszytu (issue #775, #970).
 *
 * Wyjęcie zapamiętuje się w sesji (tam ma trzy żądania życia), ale tę
 * wiedzę o kształcie zapisu i o zdaniu dla człowieka trzyma domena —
 * bez `Illuminate\Http`: dostaje zwykłą wartość z sesji, nie żądanie.
 */
final class PowrotPoWyjeciu
{
    public const TYP_PRZEPIS = 'przepis';

    public const TYP_WPIS = 'wpis';

    /**
     * Zdjęte wiersze, które wolno przywrócić dla TEGO typu i TEGO id — albo
     * `null`, gdy zapis w sesji jest inny, cudzy, pusty lub uszkodzony.
     *
     * @return list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>|null
     */
    public static function pozycje(mixed $wyjecie, string $typ, string $id): ?array
    {
        if (! is_array($wyjecie)
            || ($wyjecie['typ'] ?? null) !== $typ
            || ($wyjecie['id'] ?? null) !== $id
            || ! is_array($wyjecie['pozycje'] ?? null)
            || $wyjecie['pozycje'] === []) {
            return null;
        }

        return $wyjecie['pozycje'];
    }

    /** Zdanie po przywróceniu; `$wrocilo` to liczba faktycznie przywróconych wierszy. */
    public static function zdanie(string $typ, int $wrocilo): string
    {
        $co = $typ === self::TYP_PRZEPIS ? 'Przepis' : 'Wpis';

        return $wrocilo === 1
            ? "{$co} wrócił do zeszytu razem z notatką."
            : "{$co} wrócił do wszystkich {$wrocilo} zeszytów razem z notatkami.";
    }
}
