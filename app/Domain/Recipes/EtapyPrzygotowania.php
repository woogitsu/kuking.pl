<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Podział kroków na nazwane etapy przygotowania (#2652).
 *
 * Nagłówek etapu to pole kroku (`recipe_steps.section_name`), nie osobny
 * wiersz: etap zaczyna się od kroku z nazwą i trwa do następnego kroku
 * z nazwą. Kroki przed pierwszą nazwą tworzą grupę bez nagłówka.
 * Przepis bez ani jednej nazwy to jedna grupa bez nagłówka — widok wygląda
 * wtedy dokładnie jak przed tą funkcją.
 *
 * Klucze kroków w grupie zostają oryginalne (numer kolejny w całym przepisie),
 * więc numeracja, odcisk minutnika i postęp gotowania nie zależą od podziału.
 */
final class EtapyPrzygotowania
{
    /**
     * @template T
     *
     * @param  iterable<int, T>  $kroki  modele kroków albo wiersze migawki (tablice)
     * @return list<array{nazwa: ?string, kroki: array<int, T>}>
     */
    public static function grupy(iterable $kroki): array
    {
        $grupy = [];
        $biezaca = null;

        foreach ($kroki as $indeks => $krok) {
            $nazwa = self::nazwa(self::nazwaKroku($krok));

            if ($biezaca === null || $nazwa !== null) {
                $grupy[] = ['nazwa' => $nazwa, 'kroki' => []];
                $biezaca = array_key_last($grupy);
            }

            $grupy[$biezaca]['kroki'][$indeks] = $krok;
        }

        return $grupy;
    }

    /**
     * Nazwa etapu, do którego należy krok o danym indeksie (od 0): nazwa
     * najbliższego kroku z nazwą nie dalej niż ten krok. Null, gdy przepis nie
     * ma nazw albo krok leży przed pierwszą z nich.
     *
     * @param  iterable<int, mixed>  $kroki
     */
    public static function nazwaDlaKroku(iterable $kroki, int $indeks): ?string
    {
        $nazwa = null;

        foreach ($kroki as $i => $krok) {
            if ($i > $indeks) {
                break;
            }

            $nazwa = self::nazwa(self::nazwaKroku($krok)) ?? $nazwa;
        }

        return $nazwa;
    }

    /** Krok to model (`RecipeStep`) albo wiersz migawki wersji (tablica). */
    private static function nazwaKroku(mixed $krok): mixed
    {
        return is_array($krok) ? ($krok['section_name'] ?? null) : ($krok->section_name ?? null);
    }

    private static function nazwa(mixed $wartosc): ?string
    {
        $nazwa = trim((string) $wartosc);

        return $nazwa === '' ? null : $nazwa;
    }
}
