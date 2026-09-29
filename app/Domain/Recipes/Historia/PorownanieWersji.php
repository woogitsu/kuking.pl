<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

/**
 * Porównanie dwóch zapisanych migawek przepisu: co dodano, usunięto
 * i zmieniono (issue #2024).
 *
 * Działa wyłącznie na dwóch migawkach — nie zna ani bazy, ani przepisu, więc
 * nie może przypadkiem dołożyć do wyniku dzisiejszej treści.
 *
 * REGUŁY, KTÓRE TU STOJĄ ŚWIADOMIE
 *
 *  - Pole, którego klucza brakuje w którejkolwiek migawce, NIE jest
 *    porównywane („brak danych", nie „zmiana"): starsza migawka mogła po
 *    prostu nie znać tego pola (#896). Trafia do `bezDanych`.
 *  - Składniki migawka zapisuje bez identyfikatorów, więc parujemy je po
 *    tekście autora (bez względu na wielkość liter). Składnik z przepisanym
 *    tekstem to uczciwie „usunięto" + „dodano", a nie zgadnięta zmiana.
 *  - Kroki parujemy najdłuższym wspólnym podciągiem po treści; w miejscu,
 *    gdzie po obu stronach zostały kroki bez pary, pierwsze pary czytamy jako
 *    „zmieniono", resztę jako „dodano" albo „usunięto".
 *  - Numery kroków są dla człowieka (od 1): przy „zmieniono" i „dodano" — numer
 *    w nowszej wersji, przy „usunięto" — numer w starszej.
 */
final class PorownanieWersji
{
    public const DODANO = 'dodano';

    public const USUNIETO = 'usunieto';

    public const ZMIENIONO = 'zmieniono';

    /**
     * @param  array<string, mixed>  $starsza
     * @param  array<string, mixed>  $nowsza
     * @return array{
     *     pola: list<array{etykieta: string, rodzaj: string, przed: ?string, po: ?string}>,
     *     bezDanych: list<string>,
     *     skladniki: list<array{rodzaj: string, przed: ?string, po: ?string}>,
     *     kroki: list<array{rodzaj: string, numer: int, przed: ?string, po: ?string}>,
     *     brakZmian: bool
     * }
     */
    public static function porownaj(array $starsza, array $nowsza): array
    {
        $a = new MigawkaWersji($starsza);
        $b = new MigawkaWersji($nowsza);

        [$pola, $bezDanych] = self::pola($a, $b);
        $skladniki = self::skladniki($a->skladniki(), $b->skladniki());
        $kroki = self::kroki($a->kroki(), $b->kroki());

        return [
            'pola' => $pola,
            'bezDanych' => $bezDanych,
            'skladniki' => $skladniki,
            'kroki' => $kroki,
            'brakZmian' => $pola === [] && $skladniki === [] && $kroki === [],
        ];
    }

    /**
     * @return array{0: list<array{etykieta: string, rodzaj: string, przed: ?string, po: ?string}>, 1: list<string>}
     */
    private static function pola(MigawkaWersji $a, MigawkaWersji $b): array
    {
        $zmiany = [];
        $bezDanych = [];

        foreach (MigawkaWersji::ETYKIETY as $klucz => $etykieta) {
            $przed = $a->pole($klucz);
            $po = $b->pole($klucz);

            if (! $a->maKlucz($klucz) || ! $b->maKlucz($klucz)) {
                // Brakuje klucza po którejś stronie: nie porównujemy. Wpis
                // „brak danych" tylko wtedy, gdy druga strona coś tu ma.
                if ($przed !== null || $po !== null) {
                    $bezDanych[] = $etykieta;
                }

                continue;
            }

            if ($przed === $po) {
                continue;
            }

            $zmiany[] = [
                'etykieta' => $etykieta,
                'rodzaj' => $przed === null ? self::DODANO : ($po === null ? self::USUNIETO : self::ZMIENIONO),
                'przed' => $przed,
                'po' => $po,
            ];
        }

        return [$zmiany, $bezDanych];
    }

    /**
     * @param  list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>  $stare
     * @param  list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>  $nowe
     * @return list<array{rodzaj: string, przed: ?string, po: ?string}>
     */
    private static function skladniki(array $stare, array $nowe): array
    {
        /** @var array<string, list<int>> $kolejka */
        $kolejka = [];
        foreach ($stare as $i => $s) {
            $kolejka[self::klucz($s['text'])][] = $i;
        }

        $wynik = [];
        $sparowane = [];

        foreach ($nowe as $n) {
            $klucz = self::klucz($n['text']);

            if (! empty($kolejka[$klucz])) {
                $i = array_shift($kolejka[$klucz]);
                $sparowane[$i] = true;
                $przed = self::opisSkladnika($stare[$i]);
                $po = self::opisSkladnika($n);

                if ($przed !== $po) {
                    $wynik[] = ['rodzaj' => self::ZMIENIONO, 'przed' => $przed, 'po' => $po];
                }

                continue;
            }

            $wynik[] = ['rodzaj' => self::DODANO, 'przed' => null, 'po' => self::opisSkladnika($n)];
        }

        foreach ($stare as $i => $s) {
            if (! isset($sparowane[$i])) {
                $wynik[] = ['rodzaj' => self::USUNIETO, 'przed' => self::opisSkladnika($s), 'po' => null];
            }
        }

        return $wynik;
    }

    /**
     * @param  list<array{instruction: string, timer_seconds: ?int}>  $stare
     * @param  list<array{instruction: string, timer_seconds: ?int}>  $nowe
     * @return list<array{rodzaj: string, numer: int, przed: ?string, po: ?string}>
     */
    private static function kroki(array $stare, array $nowe): array
    {
        $n = count($stare);
        $m = count($nowe);
        $k1 = array_map(fn (array $s): string => self::klucz($s['instruction']), $stare);
        $k2 = array_map(fn (array $s): string => self::klucz($s['instruction']), $nowe);

        // Tablica najdłuższego wspólnego podciągu (max 60 × 60 kroków).
        $dl = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dl[$i][$j] = $k1[$i] === $k2[$j]
                    ? $dl[$i + 1][$j + 1] + 1
                    : max($dl[$i + 1][$j], $dl[$i][$j + 1]);
            }
        }

        $wynik = [];
        $lukaStare = [];
        $lukaNowe = [];

        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($k1[$i] === $k2[$j]) {
                $wynik = array_merge($wynik, self::luka($lukaStare, $lukaNowe, $stare, $nowe));
                $lukaStare = [];
                $lukaNowe = [];

                if ($stare[$i]['timer_seconds'] !== $nowe[$j]['timer_seconds']) {
                    $wynik[] = [
                        'rodzaj' => self::ZMIENIONO,
                        'numer' => $j + 1,
                        'przed' => $stare[$i]['instruction'].self::opisMinutnika($stare[$i]['timer_seconds']),
                        'po' => $nowe[$j]['instruction'].self::opisMinutnika($nowe[$j]['timer_seconds']),
                    ];
                }
                $i++;
                $j++;
            } elseif ($dl[$i + 1][$j] >= $dl[$i][$j + 1]) {
                $lukaStare[] = $i++;
            } else {
                $lukaNowe[] = $j++;
            }
        }
        while ($i < $n) {
            $lukaStare[] = $i++;
        }
        while ($j < $m) {
            $lukaNowe[] = $j++;
        }

        return array_merge($wynik, self::luka($lukaStare, $lukaNowe, $stare, $nowe));
    }

    /**
     * Kroki bez pary między dwoma wspólnymi: pierwsze pary to „zmieniono",
     * nadwyżka po jednej ze stron to „usunięto" albo „dodano".
     *
     * @param  list<int>  $lukaStare  indeksy w starszej wersji
     * @param  list<int>  $lukaNowe  indeksy w nowszej wersji
     * @param  list<array{instruction: string, timer_seconds: ?int}>  $stare
     * @param  list<array{instruction: string, timer_seconds: ?int}>  $nowe
     * @return list<array{rodzaj: string, numer: int, przed: ?string, po: ?string}>
     */
    private static function luka(array $lukaStare, array $lukaNowe, array $stare, array $nowe): array
    {
        $wynik = [];
        $pary = min(count($lukaStare), count($lukaNowe));

        for ($p = 0; $p < $pary; $p++) {
            $wynik[] = [
                'rodzaj' => self::ZMIENIONO,
                'numer' => $lukaNowe[$p] + 1,
                'przed' => $stare[$lukaStare[$p]]['instruction'],
                'po' => $nowe[$lukaNowe[$p]]['instruction'],
            ];
        }
        foreach (array_slice($lukaStare, $pary) as $i) {
            $wynik[] = ['rodzaj' => self::USUNIETO, 'numer' => $i + 1, 'przed' => $stare[$i]['instruction'], 'po' => null];
        }
        foreach (array_slice($lukaNowe, $pary) as $j) {
            $wynik[] = ['rodzaj' => self::DODANO, 'numer' => $j + 1, 'przed' => null, 'po' => $nowe[$j]['instruction']];
        }

        return $wynik;
    }

    private static function opisMinutnika(?int $sekundy): string
    {
        $tekst = MigawkaWersji::minutnik($sekundy);

        return ' (minutnik: '.($tekst ?? 'brak').')';
    }

    /**
     * @param  array{group_name: ?string, text: string, note: ?string, substitutes: ?string}  $s
     */
    private static function opisSkladnika(array $s): string
    {
        $opis = $s['text'];

        if ($s['note'] !== null) {
            $opis .= ' — '.$s['note'];
        }
        if ($s['substitutes'] !== null) {
            $opis .= '. Zamiast tego: '.$s['substitutes'];
        }
        if ($s['group_name'] !== null) {
            $opis .= ' (grupa: '.$s['group_name'].')';
        }

        return $opis;
    }

    private static function klucz(string $tekst): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($tekst)) ?? $tekst);
    }
}
