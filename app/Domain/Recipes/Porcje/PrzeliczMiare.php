<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Równoważniki miar przy składniku, na żądanie widza (#2533, V2).
 *
 * Tylko masa↔masa (g, dag, kg) i objętość↔objętość (ml, l oraz miary
 * kuchenne: szklanka, łyżka, łyżeczka). Masy NIE zamieniamy na objętość
 * ani odwrotnie — to zależy od produktu (szklanka mąki to nie szklanka
 * miodu), więc nie zgadujemy.
 *
 * Ilość czyta ten sam parser, co skalowanie porcji (`PrzeliczSkladnik`),
 * z tekstu już po wyborze porcji. Nic nie trafia do bazy; tekst autora
 * zostaje bez zmian. Zaokrąglenie: gramy do pełnych, mililitry kuchenne
 * do 5, z „ok.” przy miarach kuchennych (szklanka 250 ml, łyżka 15 ml,
 * łyżeczka 5 ml).
 */
final class PrzeliczMiare
{
    /** Mililitry w jednej mierze kuchennej. */
    private const KUCHENNE_ML = ['szklanka' => 250.0, 'lyzka' => 15.0, 'lyzeczka' => 5.0];

    /** Gramy w jednej jednostce masy. */
    private const MASA_G = ['g' => 1.0, 'dag' => 10.0, 'kg' => 1000.0];

    /**
     * @return list<string> np. ['250 g', '0,25 kg'] — pusta lista, gdy nie ma czego przeliczyć
     */
    public static function dla(string $tekstSkladnika): array
    {
        $ilosc = PrzeliczSkladnik::odczytaj($tekstSkladnika);

        if ($ilosc === null) {
            return [];
        }

        $klucz = $ilosc['jednostka']->klucz;
        $liczby = $ilosc['do'] === null ? [$ilosc['od']] : [$ilosc['od'], $ilosc['do']];

        if (min($liczby) <= 0) {
            return [];
        }

        if (isset(self::MASA_G[$klucz])) {
            return self::masa($klucz, $liczby);
        }

        if ($klucz === 'ml' || $klucz === 'l' || isset(self::KUCHENNE_ML[$klucz])) {
            return self::objetosc($klucz, $liczby);
        }

        return [];
    }

    /**
     * @param  list<float>  $liczby
     * @return list<string>
     */
    private static function masa(string $klucz, array $liczby): array
    {
        $wGramach = array_map(static fn (float $l): float => $l * self::MASA_G[$klucz], $liczby);
        $wyniki = [];

        foreach (array_keys(self::MASA_G) as $cel) {
            if ($cel === $klucz) {
                continue;
            }

            $wyniki[] = self::zakres(array_map(static fn (float $g): float => $g / self::MASA_G[$cel], $wGramach), $cel);
        }

        return $wyniki;
    }

    /**
     * @param  list<float>  $liczby
     * @return list<string>
     */
    private static function objetosc(string $klucz, array $liczby): array
    {
        $kuchenna = isset(self::KUCHENNE_ML[$klucz]);
        $mnoznik = $kuchenna ? self::KUCHENNE_ML[$klucz] : ($klucz === 'l' ? 1000.0 : 1.0);
        $wMl = array_map(static fn (float $l): float => $l * $mnoznik, $liczby);
        $wyniki = [];

        if ($klucz !== 'ml') {
            $mililitry = $kuchenna
                ? array_map(static fn (float $m): float => max(5.0, round($m / 5) * 5), $wMl)
                : $wMl;
            $wyniki[] = ($kuchenna ? 'ok. ' : '').self::zakres($mililitry, 'ml');
        }

        if ($klucz !== 'l' && ! $kuchenna) {
            $wyniki[] = self::zakres(array_map(static fn (float $m): float => $m / 1000, $wMl), 'l');
        }

        return $wyniki;
    }

    /** @param list<float> $liczby */
    private static function zakres(array $liczby, string $jednostka): string
    {
        $zapis = array_map(static fn (float $l): string => self::liczba($l, $jednostka), $liczby);

        return implode('–', array_unique($zapis)).' '.$jednostka;
    }

    /** Gramy i ml od 1 w górę do pełnych; reszta do trzech miejsc, z przecinkiem. */
    private static function liczba(float $l, string $jednostka): string
    {
        if (in_array($jednostka, ['g', 'ml'], true) && $l >= 1) {
            return (string) (int) round($l);
        }

        $zapis = number_format(round($l, 3), 3, ',', '');

        // Mała dodatnia wartość nie może zaokrąglić się do zera.
        if ((float) str_replace(',', '.', $zapis) <= 0.0) {
            return number_format($l, 6, ',', '');
        }

        return rtrim(rtrim($zapis, '0'), ',');
    }
}
