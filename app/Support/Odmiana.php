<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Odmiana liczebnika po polsku.
 *
 * PO CO OSOBNA KLASA
 * Reguła istniała już w `Recipe::odmianaPorcji()`, ale prywatnie i tylko dla
 * porcji. Wyszukiwarka miała własną, dwustanową: `count === 1 ? 'przepis'
 * : 'przepisów'` — czyli dla trzech wyników pisała „Znaleziono 3 przepisów".
 *
 * Polski ma trzy formy, nie dwie, i wyjątek na nastki: 2, 3, 4 biorą formę
 * mnogą („przepisy"), ale 12, 13, 14 już nie. Reguła zapisana raz jest
 * jedynym sposobem, żeby trzeci ekran nie wymyślił jej po swojemu po raz
 * trzeci.
 */
final class Odmiana
{
    /**
     * @param  string  $jeden  forma dla 1 — „przepis"
     * @param  string  $kilka  forma dla 2-4 — „przepisy"
     * @param  string  $wiele  forma dla 0, 5+ i nastek — „przepisów"
     */
    public static function rzeczownik(int $ile, string $jeden, string $kilka, string $wiele): string
    {
        $ile = abs($ile);

        if ($ile === 1) {
            return $jeden;
        }

        $jednosci = $ile % 10;
        $dwieOstatnie = $ile % 100;

        // Nastki (12, 13, 14, ale też 112, 213...) biorą formę „wiele",
        // mimo że kończą się cyfrą z przedziału 2-4.
        if ($jednosci >= 2 && $jednosci <= 4 && ($dwieOstatnie < 12 || $dwieOstatnie > 14)) {
            return $kilka;
        }

        return $wiele;
    }
}
