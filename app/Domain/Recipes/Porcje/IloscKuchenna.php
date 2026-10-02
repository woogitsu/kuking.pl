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

    /**
     * Zaokrąglona wartość — ta sama, którą potem wypisuje `zapis()`.
     *
     * Masę i objętość (g, dag, kg, ml, l) zaokrąglamy ZAWSZE w jednostce
     * bazowej g/ml, a dopiero potem wracamy do jednostki autora (#2655).
     * Dzięki temu „0,1 kg” i „100 g” po tym samym mnożniku dają tę samą
     * fizyczną ilość. `$wspolczynnik` to liczba g/ml w jednostce autora
     * (dag 10, kg 1000); bez niego kg i l liczymy jako 1000.
     */
    public static function zaokraglij(float $ile, string $rodzaj, ?float $wspolczynnik = null): float
    {
        if ($ile <= 0) {
            return 0.0;
        }

        if ($rodzaj === JednostkaKuchenna::METRYCZNA || $rodzaj === JednostkaKuchenna::METRYCZNA_DUZA) {
            $wspolczynnik ??= $rodzaj === JednostkaKuchenna::METRYCZNA_DUZA ? 1000.0 : 1.0;
            // round(…, 6) wyrównuje szum zmiennoprzecinkowy (0,1 kg × 1000
            // to 100,00000000000001), żeby oba zapisy trafiały w ten sam krok.
            $baza = round($ile * $wspolczynnik, 6);

            return round(self::wBazie($baza) / $wspolczynnik, 6);
        }

        return self::doUlamka($ile);
    }

    /** Zapis po polsku: „260”, „1,25”, „0,025”, „1½”, „¾”. */
    public static function zapis(float $ile, string $rodzaj, ?float $wspolczynnik = null): string
    {
        $ile = self::zaokraglij($ile, $rodzaj, $wspolczynnik);

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

    /** Krok kuchenny w gramach albo mililitrach; nigdy poniżej jednego kroku. */
    private static function wBazie(float $ile): float
    {
        return self::doKroku($ile, match (true) {
            $ile < 1 => 0.1,
            $ile < 10 => 0.5,
            $ile < 30 => 1.0,
            $ile < 100 => 5.0,
            $ile < 1000 => 10.0,
            default => 50.0,
        });
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
        // Do czterech miejsc: 25 g w kilogramach to 0,025, a 0,5 g — 0,0005.
        return rtrim(rtrim(number_format($ile, 4, ',', ''), '0'), ',');
    }
}
