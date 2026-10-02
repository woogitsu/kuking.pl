<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Domain\Recipes\GrupySkladnikow;

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
     * @param  bool  $zAlergenami  flaga `kuking.alergeny.wlaczone` — funkcja jest czysta, więc nie czyta konfiguracji sama
     * @return array{
     *     pola: list<array{etykieta: string, rodzaj: string, przed: ?string, po: ?string}>,
     *     bezDanych: list<string>,
     *     skladniki: list<array{rodzaj: string, przed: ?string, po: ?string}>,
     *     zmienionaKolejnoscSkladnikow: bool,
     *     kroki: list<array{rodzaj: string, numer: int, przed: ?string, po: ?string}>,
     *     brakZmian: bool,
     *     bezWykrytychZmian: bool
     * }
     */
    public static function porownaj(array $starsza, array $nowsza, bool $zAlergenami = false): array
    {
        $a = new MigawkaWersji($starsza);
        $b = new MigawkaWersji($nowsza);

        [$pola, $bezDanych] = self::pola($a, $b, $zAlergenami);
        $stareSkladniki = $a->skladniki();
        $noweSkladniki = $b->skladniki();
        $skladniki = self::skladniki($stareSkladniki, $noweSkladniki);
        $zmienionaKolejnoscSkladnikow = self::zmienionaKolejnoscSkladnikow($stareSkladniki, $noweSkladniki);
        $kroki = self::kroki($a->kroki(), $b->kroki());

        return [
            'pola' => $pola,
            'bezDanych' => $bezDanych,
            'skladniki' => $skladniki,
            'zmienionaKolejnoscSkladnikow' => $zmienionaKolejnoscSkladnikow,
            'kroki' => $kroki,
            // „Brak zmian" tylko wtedy, gdy porównanie objęło WSZYSTKO
            // (#2240). Pole z wartością po jednej stronie i bez klucza po
            // drugiej to „nie wiemy", nie „bez zmian" — ekran nie może
            // wtedy twierdzić, że wersje są identyczne.
            'brakZmian' => $pola === [] && $skladniki === [] && ! $zmienionaKolejnoscSkladnikow && $kroki === [] && $bezDanych === [],
            // Nic z tego, co da się porównać, się nie zmieniło, ale części
            // pól porównać się nie da. Osobny stan, żeby widok nie mówił
            // naraz „nie ma różnic" i „tego nie da się porównać".
            'bezWykrytychZmian' => $pola === [] && $skladniki === [] && ! $zmienionaKolejnoscSkladnikow && $kroki === [] && $bezDanych !== [],
        ];
    }

    /**
     * Porównuje układ widoczny na stronie, a nie numery position. Wspólne
     * wiersze rozpoznaje po całym widocznym opisie i numerze wystąpienia;
     * dodany albo usunięty wiersz nie przesuwa pozostałych w „zmiany”.
     *
     * @param  list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>  $stare
     * @param  list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>  $nowe
     */
    private static function zmienionaKolejnoscSkladnikow(array $stare, array $nowe): bool
    {
        $stareGrupy = self::grupyDoPorownania($stare);
        $noweGrupy = self::grupyDoPorownania($nowe);
        $wspolne = array_intersect(array_keys($stareGrupy), array_keys($noweGrupy));
        $staraKolejnoscGrup = array_values(array_intersect(array_keys($stareGrupy), $wspolne));
        $nowaKolejnoscGrup = array_values(array_intersect(array_keys($noweGrupy), $wspolne));

        if ($staraKolejnoscGrup !== $nowaKolejnoscGrup) {
            return true;
        }

        foreach ($wspolne as $grupa) {
            $licznikiStare = array_count_values($stareGrupy[$grupa]);
            $licznikiNowe = array_count_values($noweGrupy[$grupa]);
            $limity = [];
            foreach ($licznikiStare as $klucz => $liczba) {
                $limity[$klucz] = min($liczba, $licznikiNowe[$klucz] ?? 0);
            }

            if (self::wspolnaKolejnosc($stareGrupy[$grupa], $limity) !== self::wspolnaKolejnosc($noweGrupy[$grupa], $limity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>  $skladniki
     * @return array<string, list<string>>
     */
    private static function grupyDoPorownania(array $skladniki): array
    {
        $wynik = [];
        foreach (GrupySkladnikow::ulozyc($skladniki) as $grupa) {
            $klucz = $grupa['nazwa'] === null ? '' : GrupySkladnikow::klucz($grupa['nazwa']);
            $wynik[$klucz] = array_map(
                fn (array $wiersz): string => serialize([$wiersz['text'], $wiersz['note'], $wiersz['substitutes']]),
                $grupa['skladniki'],
            );
        }

        return $wynik;
    }

    /**
     * @param  list<string>  $wiersze
     * @param  array<string, int>  $limity
     * @return list<string>
     */
    private static function wspolnaKolejnosc(array $wiersze, array $limity): array
    {
        $widziane = [];
        $wynik = [];
        foreach ($wiersze as $klucz) {
            $widziane[$klucz] = ($widziane[$klucz] ?? 0) + 1;
            if ($widziane[$klucz] <= ($limity[$klucz] ?? 0)) {
                $wynik[] = $klucz;
            }
        }

        return $wynik;
    }

    /**
     * @return array{0: list<array{etykieta: string, rodzaj: string, przed: ?string, po: ?string}>, 1: list<string>}
     */
    private static function pola(MigawkaWersji $a, MigawkaWersji $b, bool $zAlergenami): array
    {
        $zmiany = [];
        $bezDanych = [];

        // Alergeny (#1902) tylko przy włączonej fladze — `SnapshotRecipeVersion`
        // tworzy wersję przy samej zmianie oznaczenia, więc bez tej pozycji
        // porównanie pokazałoby „brak zmian” dla dwóch różnych wersji.
        $pozycje = MigawkaWersji::ETYKIETY;
        if ($zAlergenami) {
            $pozycje['allergen_status'] = MigawkaWersji::ETYKIETA_ALERGENOW;
        }

        foreach ($pozycje as $klucz => $etykieta) {
            $przed = $klucz === 'allergen_status' ? $a->alergeny() : $a->pole($klucz);
            $po = $klucz === 'allergen_status' ? $b->alergeny() : $b->pole($klucz);

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
     * @param  list<array{instruction: string, timer_seconds: ?int, section_name: ?string}>  $stare
     * @param  list<array{instruction: string, timer_seconds: ?int, section_name: ?string}>  $nowe
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

                if ($stare[$i]['timer_seconds'] !== $nowe[$j]['timer_seconds']
                    || $stare[$i]['section_name'] !== $nowe[$j]['section_name']) {
                    $etapZmieniony = $stare[$i]['section_name'] !== $nowe[$j]['section_name'];
                    $zMinutnikiem = $stare[$i]['timer_seconds'] !== null || $nowe[$j]['timer_seconds'] !== null;
                    $wynik[] = [
                        'rodzaj' => self::ZMIENIONO,
                        'numer' => $j + 1,
                        'przed' => self::opisPary($stare[$i], $zMinutnikiem, $etapZmieniony),
                        'po' => self::opisPary($nowe[$j], $zMinutnikiem, $etapZmieniony),
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
     * @param  list<array{instruction: string, timer_seconds: ?int, section_name: ?string}>  $stare
     * @param  list<array{instruction: string, timer_seconds: ?int, section_name: ?string}>  $nowe
     * @return list<array{rodzaj: string, numer: int, przed: ?string, po: ?string}>
     */
    private static function luka(array $lukaStare, array $lukaNowe, array $stare, array $nowe): array
    {
        $wynik = [];
        $pary = min(count($lukaStare), count($lukaNowe));

        for ($p = 0; $p < $pary; $p++) {
            $staryKrok = $stare[$lukaStare[$p]];
            $nowyKrok = $nowe[$lukaNowe[$p]];
            // Gdy choć jedna strona ma minutnik, opisujemy obie (brak = „brak”,
            // nie zgadnięty czas); bez minutnika po obu stronach — sam tekst.
            $zMinutnikiem = $staryKrok['timer_seconds'] !== null || $nowyKrok['timer_seconds'] !== null;
            $wynik[] = [
                'rodzaj' => self::ZMIENIONO,
                'numer' => $lukaNowe[$p] + 1,
                'przed' => self::opisPary($staryKrok, $zMinutnikiem, false),
                'po' => self::opisPary($nowyKrok, $zMinutnikiem, false),
            ];
        }
        foreach (array_slice($lukaStare, $pary) as $i) {
            $wynik[] = ['rodzaj' => self::USUNIETO, 'numer' => $i + 1, 'przed' => self::opisKroku($stare[$i]), 'po' => null];
        }
        foreach (array_slice($lukaNowe, $pary) as $j) {
            $wynik[] = ['rodzaj' => self::DODANO, 'numer' => $j + 1, 'przed' => null, 'po' => self::opisKroku($nowe[$j])];
        }

        return $wynik;
    }

    /**
     * Krok dodany albo usunięty: czas tylko wtedy, gdy migawka go zapisała.
     *
     * @param  array{instruction: string, timer_seconds: ?int, section_name: ?string}  $krok
     */
    private static function opisKroku(array $krok): string
    {
        return self::opisPary($krok, $krok['timer_seconds'] !== null, false);
    }

    /**
     * Treść kroku z nazwą etapu (#2652) i, jeśli trzeba, minutnikiem. Krok
     * parowany po treści opisujemy zawsze z etapem, żeby zmiana samej nazwy
     * etapu była widoczna po obu stronach; w pozostałych przypadkach etap
     * dopisujemy tylko tam, gdzie krok go ma.
     *
     * @param  array{instruction: string, timer_seconds: ?int, section_name: ?string}  $krok
     */
    private static function opisPary(array $krok, bool $zMinutnikiem, bool $zawszeEtap): string
    {
        $etap = $krok['section_name'] !== null
            ? '[Etap: '.$krok['section_name'].'] '
            : ($zawszeEtap ? '[Bez etapu] ' : '');

        return $etap.$krok['instruction'].($zMinutnikiem ? self::opisMinutnika($krok['timer_seconds']) : '');
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
