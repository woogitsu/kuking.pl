<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Zbiorcza zmiana nazwy grupy składników w kreatorze (V2, #2444).
 *
 * Grupa nie ma własnej tabeli (D-033) — jest polem `group_name` przy każdym
 * składniku. Zmiana nagłówka „Ciasto" na „Spód" to więc zmiana tego jednego
 * pola w KAŻDYM wierszu grupy. Ta klasa wyznacza, które to wiersze, i robi
 * tylko to: nie rusza `_key`, tekstu, uwagi, zamiennika, `no_amount` ani
 * kolejności wierszy.
 *
 * ZAKRES WYZNACZA `GrupySkladnikow::klucz()`, nie dosłowna pisownia i nie
 * sąsiedztwo: „Ciasto", „ciasto" i „ Ciasto  " to jedna grupa, wiersze
 * rozdzielone inną grupą też, a „Sos" i „Sós" pozostają różne.
 *
 * Klasa nie zna Livewire'a ani bazy. Dostaje wiersze z kreatora i zwraca
 * nowe wiersze albo opis tego, co się stanie — decyzję o zapisie podejmuje
 * komponent po jawnym zatwierdzeniu.
 */
final class ZmianaNazwyGrupy
{
    /**
     * Grupy występujące w wierszach, w kolejności pierwszego wystąpienia.
     *
     * @param  iterable<mixed>  $skladniki
     * @return list<array{klucz: string, nazwa: string, liczba: int}>
     */
    public static function grupy(iterable $skladniki): array
    {
        /** @var array<string, array{klucz: string, nazwa: string, liczba: int}> $grupy */
        $grupy = [];

        foreach ($skladniki as $skladnik) {
            $nazwa = GrupySkladnikow::nazwa($skladnik);

            if ($nazwa === null) {
                continue;
            }

            $klucz = GrupySkladnikow::klucz($nazwa);
            $grupy[$klucz] ??= ['klucz' => $klucz, 'nazwa' => $nazwa, 'liczba' => 0];
            $grupy[$klucz]['liczba']++;
        }

        return array_values($grupy);
    }

    /**
     * Opis zmiany do pokazania PRZED zatwierdzeniem albo `null`, gdy grupa
     * o tym kluczu już nie istnieje (np. po ręcznej zmianie wierszy).
     *
     * `scala` — nazwa pasuje do INNEJ istniejącej grupy: wiersze trafią pod
     * jej nagłówek. `bezNaglowka` — pusta nazwa: wiersze zostają, tracą tylko
     * nagłówek. `bezZmiany` — każdy wiersz ma już dokładnie tę nazwę.
     *
     * @param  iterable<mixed>  $skladniki
     * @return array{klucz: string, obecna: string, nowa: string, liczba: int, scala: ?array{nazwa: string, liczba: int}, bezNaglowka: bool, bezZmiany: bool}|null
     */
    public static function opis(iterable $skladniki, string $klucz, string $nowaNazwa): ?array
    {
        $wiersze = is_array($skladniki) ? $skladniki : iterator_to_array($skladniki, false);
        $obecna = null;
        $liczba = 0;
        $roznePisownie = false;
        $nowa = self::oczyscNazwe($nowaNazwa);

        foreach ($wiersze as $wiersz) {
            $nazwa = GrupySkladnikow::nazwa($wiersz);

            if ($nazwa === null || GrupySkladnikow::klucz($nazwa) !== $klucz) {
                continue;
            }

            $obecna ??= $nazwa;
            $liczba++;

            if ($nazwa !== $nowa) {
                $roznePisownie = true;
            }
        }

        if ($obecna === null) {
            return null;
        }

        $scala = null;

        if ($nowa !== '') {
            $kluczNowej = GrupySkladnikow::klucz($nowa);

            foreach (self::grupy($wiersze) as $grupa) {
                if ($grupa['klucz'] === $kluczNowej && $grupa['klucz'] !== $klucz) {
                    $scala = ['nazwa' => $grupa['nazwa'], 'liczba' => $grupa['liczba']];
                    $nowa = $grupa['nazwa'];

                    break;
                }
            }
        }

        return [
            'klucz' => $klucz,
            'obecna' => $obecna,
            'nowa' => $nowa,
            'liczba' => $liczba,
            'scala' => $scala,
            'bezNaglowka' => $nowa === '',
            'bezZmiany' => ! $roznePisownie,
        ];
    }

    /**
     * Wiersze po zmianie: tylko `group_name` wierszy wskazanej grupy.
     *
     * Przy scaleniu wiersze dostają pisownię istniejącej grupy docelowej
     * (tę, którą i tak pokazałby `GrupySkladnikow::ulozyc()`), a nie pisownię
     * wpisaną przy okazji — nagłówek nie rozdwaja się przez wielkość liter.
     *
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $skladniki
     * @return list<T>
     */
    public static function zastosuj(array $skladniki, string $klucz, string $nowaNazwa): array
    {
        $opis = self::opis($skladniki, $klucz, $nowaNazwa);

        if ($opis === null) {
            return $skladniki;
        }

        foreach ($skladniki as $i => $wiersz) {
            $nazwa = GrupySkladnikow::nazwa($wiersz);

            if ($nazwa !== null && GrupySkladnikow::klucz($nazwa) === $klucz) {
                $skladniki[$i]['group_name'] = $opis['nowa'];
            }
        }

        return $skladniki;
    }

    /** Przycina brzegi i zbiera powtórzone odstępy — nigdy nie skraca treści. */
    public static function oczyscNazwe(string $nazwa): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nazwa));
    }
}
