<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Zaokrąglenie i zapis ilości po przeliczeniu porcji (D-284).
 *
 * Mnożenie daje liczby, których nikt w kuchni nie odmierzy: 4 porcje na 6
 * to mnożnik 1,5, a 1,5 × 1/3 szklanki to 0,5 szklanki — to jeszcze się
 * udaje — ale 1,5 × 175 g to 262,5 g, a 5/4 × 1 jajko to 1,25 jajka.
 * Czytelne jest „260 g” i „1¼ jajka”, więc zaokrąglamy do kroku, który
 * da się odmierzyć tym, co się ma w szufladzie.
 *
 * Wynik nigdy nie jest zerem: składnik, który autor wpisał, nie może
 * zniknąć z przepisu dlatego, że ktoś wybrał jedną porcję.
 */
final class IloscKuchenna
{
    /** Ułamki zwykłe, które mają własny znak i które ludzie czytają bez liczenia. */
    private const ULAMKI = [
        '⅛' => 1 / 8,
        '¼' => 1 / 4,
        '⅓' => 1 / 3,
        '½' => 1 / 2,
        '⅔' => 2 / 3,
        '¾' => 3 / 4,
    ];

    /** Zaokrąglona wartość — ta sama, którą potem wypisuje `zapis()`. */
    public static function zaokraglij(float $ile, string $rodzaj): float
    {
        if ($ile <= 0) {
            return 0.0;
        }

        return match ($rodzaj) {
            JednostkaKuchenna::METRYCZNA => self::doKroku($ile, match (true) {
                $ile < 1 => 0.1,
                $ile < 10 => 0.5,
                $ile < 30 => 1.0,
                $ile < 100 => 5.0,
                $ile < 1000 => 10.0,
                default => 50.0,
            }),
            JednostkaKuchenna::METRYCZNA_DUZA => self::doKroku($ile, 0.05),
            default => self::doUlamka($ile),
        };
    }

    /** Zapis po polsku: „260”, „1,25”, „1½”, „¾”. */
    public static function zapis(float $ile, string $rodzaj): string
    {
        $ile = self::zaokraglij($ile, $rodzaj);

        if ($rodzaj === JednostkaKuchenna::METRYCZNA || $rodzaj === JednostkaKuchenna::METRYCZNA_DUZA) {
            return self::dziesietnie($ile);
        }

        $calosci = (int) floor($ile + 1e-9);
        $reszta = $ile - $calosci;

        if ($reszta < 1e-6) {
            return (string) $calosci;
        }

        foreach (self::ULAMKI as $znak => $wartosc) {
            if (abs($reszta - $wartosc) < 1e-6) {
                return ($calosci > 0 ? (string) $calosci : '').$znak;
            }
        }

        return self::dziesietnie($ile);
    }

    private static function doKroku(float $ile, float $krok): float
    {
        return max($krok, round($ile / $krok) * $krok);
    }

    /**
     * Do 5 — najbliższy z ułamków ¼ ⅓ ½ ⅔ ¾ (poniżej ¼ także ⅛), do 10 —
     * do połówki, powyżej — do całości. „7⅔ jajka” jest dokładne i nikomu
     * nie pomaga; „7½” i „12” pomagają.
     */
    private static function doUlamka(float $ile): float
    {
        if ($ile >= 10) {
            return max(1.0, round($ile));
        }

        if ($ile >= 5) {
            return round($ile * 2) / 2;
        }

        $calosci = floor($ile);
        $reszta = $ile - $calosci;

        $kandydaci = [0.0, 1 / 4, 1 / 3, 1 / 2, 2 / 3, 3 / 4, 1.0];

        if ($calosci < 1) {
            $kandydaci[] = 1 / 8;
        }

        $najblizszy = 0.0;
        $roznica = INF;

        foreach ($kandydaci as $kandydat) {
            if (abs($reszta - $kandydat) < $roznica - 1e-9) {
                $roznica = abs($reszta - $kandydat);
                $najblizszy = $kandydat;
            }
        }

        $wynik = $calosci + $najblizszy;

        return $wynik > 0 ? $wynik : 1 / 8;
    }

    private static function dziesietnie(float $ile): string
    {
        return rtrim(rtrim(number_format($ile, 2, ',', ''), '0'), ',');
    }
}
