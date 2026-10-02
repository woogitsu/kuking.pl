<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

/**
 * Przeliczanie temperatur °F ↔ °C z tekstu kroku (#2585, D-333).
 *
 * Czysta funkcja: dostaje tekst kroku, zwraca listę przeliczeń do pokazania
 * POD tekstem w rozwijanym bloku. Niczego nie zapisuje i nie zmienia treści
 * przepisu — instrukcja autora zostaje dosłownie taka, jak ją wpisał.
 *
 * CO UZNAJEMY ZA TEMPERATURĘ (ostrożnie, żeby nie łapać „F” w zwykłym tekście)
 * Tylko liczbę z jawną jednostką: „350°F”, „350 °F”, „180°C”, „-18 °C”,
 * „177,5°C”, „170–180°C” oraz „180 stopni Celsjusza”, „350 stopni
 * Fahrenheita”. Samo „350 stopni”, „350 F” bez znaku stopnia, „F1” czy słowo
 * „Fajny” nie są temperaturą — jednostki nie zgadujemy.
 *
 * PRZYBLIŻENIE
 * Wynik zaokrąglamy do 5 stopni w obu kierunkach i zawsze piszemy „około”.
 * Piekarnik ustawia się co 5 °C, więc 5 °F nie gubi niczego, co da się
 * ustawić; 10 °F byłoby grubsze niż pokrętło (180 °C = 356 °F wyszłoby
 * 360 °F, a 100 °C = 212 °F wyszłoby 210 °F). To kulinarny odpowiednik,
 * nie dokładny rachunek.
 */
final class Temperatury
{
    /** Najwięcej przeliczeń pod jednym krokiem — dłuższa lista przestaje być pomocą. */
    public const MAKSIMUM = 4;

    /** Wartości spoza tego zakresu to nie temperatura z kuchni (np. „2000°C”). */
    private const GRANICA = 1000.0;

    private const KROK_ZAOKRAGLENIA = 5;

    private const WZORZEC = '/(?<![\p{L}\p{N}.,])'
        .'(?<od>[-−]?\d{1,4}(?:[.,]\d{1,2})?)'
        .'(?:\s*(?:-|–|—|do)\s*(?<do>\d{1,4}(?:[.,]\d{1,2})?))?'
        .'[\s\x{00A0}]*'
        .'(?:[°º˚][\s\x{00A0}]*(?<znak>[CcFf])'
        .'|(?:stopni|stopnie|stopnia|stopień)[\s\x{00A0}]+(?<nazwa>Celsjusza|Celsjusz|Fahrenheita|Fahrenheit))'
        .'(?![\p{L}\p{N}])/u';

    /**
     * @return list<array{oryginal: string, wynik: string, tekst: string}>
     */
    public static function wTekscie(string $tekst): array
    {
        if (preg_match_all(self::WZORZEC, $tekst, $trafienia, PREG_SET_ORDER) === false) {
            return [];
        }

        $wyniki = [];

        foreach ($trafienia as $trafienie) {
            $jednostka = ($trafienie['znak'] ?? '') !== '' ? $trafienie['znak'] : ($trafienie['nazwa'] ?? '');
            $zFahrenheita = stripos($jednostka, 'f') === 0;

            $od = self::liczba($trafienie['od']);
            $do = ($trafienie['do'] ?? '') !== '' ? self::liczba($trafienie['do']) : null;

            if (abs($od) > self::GRANICA || ($do !== null && abs($do) > self::GRANICA)) {
                continue;
            }

            $oryginal = trim($trafienie[0]);
            $docelowa = $zFahrenheita ? '°C' : '°F';
            // Jednostka tylko na końcu: „340–355°F”.
            $wynik = $do === null
                ? self::tekstLiczby($od, $zFahrenheita).$docelowa
                : self::tekstLiczby($od, $zFahrenheita).'–'.self::tekstLiczby($do, $zFahrenheita).$docelowa;

            // Ta sama temperatura wpisana dwa razy w kroku ma jeden wiersz.
            $wyniki[$oryginal] = [
                'oryginal' => $oryginal,
                'wynik' => $wynik,
                'tekst' => $oryginal.' to około '.$wynik,
            ];

            if (count($wyniki) >= self::MAKSIMUM) {
                break;
            }
        }

        return array_values($wyniki);
    }

    /** Dokładny rachunek bez zaokrąglenia (C = (F − 32) / 1,8; F = C × 1,8 + 32). */
    public static function przelicz(float $wartosc, bool $zFahrenheita): float
    {
        return $zFahrenheita ? ($wartosc - 32) / 1.8 : $wartosc * 1.8 + 32;
    }

    /** Wynik przeliczenia zaokrąglony do 5 stopni, z minusem typograficznym. */
    private static function tekstLiczby(float $wartosc, bool $zFahrenheita): string
    {
        $zaokraglone = (int) (floor(self::przelicz($wartosc, $zFahrenheita) / self::KROK_ZAOKRAGLENIA + 0.5) * self::KROK_ZAOKRAGLENIA);

        return ($zaokraglone < 0 ? '−' : '').abs($zaokraglone);
    }

    private static function liczba(string $surowa): float
    {
        return (float) str_replace([',', '−'], ['.', '-'], $surowa);
    }
}
