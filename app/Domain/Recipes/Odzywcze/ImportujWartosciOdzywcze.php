<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wczytuje tabelę wartości odżywczych z plików w repozytorium (D-299).
 *
 * `database/data/odzywcze/skladniki.csv` i `miary.csv` są JEDYNYM źródłem.
 * Import jest idempotentny: po nim baza zawiera dokładnie to, co pliki —
 * pozycje, których w pliku już nie ma, znikają razem z miarami i aliasami,
 * a te same pliki wczytane drugi raz niczego nie zmieniają. Całość idzie
 * w jednej transakcji, więc błąd w połowie pliku nie zostawia pół tabeli.
 *
 * Żadnego pobierania z sieci — to jest świadoma decyzja właściciela
 * (26.09.2026): dane do produkcji przychodzą z repozytorium, w PR-ze,
 * który ktoś przeczytał.
 */
final class ImportujWartosciOdzywcze
{
    public const KATALOG = 'database/data/odzywcze';

    private const KOLUMNY_SKLADNIKOW = ['klucz', 'nazwa', 'aliasy', 'zrodlo', 'zrodlo_id', 'gestosc_g_ml', 'pomijalny', 'zrodlo_nazwa', 'kcal', 'bialko', 'tluszcz', 'weglowodany'];

    private const KOLUMNY_MIAR = ['klucz', 'jednostka', 'gramy', 'uwagi'];

    /**
     * @return array{skladniki: int, aliasy: int, miary: int, usuniete: int}
     *
     * @throws RuntimeException gdy plik jest niepoprawny — z numerem wiersza
     */
    public function handle(?string $katalog = null): array
    {
        $katalog ??= base_path(self::KATALOG);
        $skladniki = $this->czytajCsv($katalog.'/skladniki.csv', self::KOLUMNY_SKLADNIKOW);
        $miary = $this->czytajCsv($katalog.'/miary.csv', self::KOLUMNY_MIAR);

        [$pozycje, $aliasy] = $this->sprawdzSkladniki($skladniki);
        $miaryDoZapisu = $this->sprawdzMiary($miary, $pozycje);

        return DB::transaction(function () use ($pozycje, $aliasy, $miaryDoZapisu): array {
            $usuniete = SkladnikOdzywczy::query()->whereNotIn('klucz', array_keys($pozycje))->delete();
            $idPoKluczu = [];

            foreach ($pozycje as $klucz => $dane) {
                $idPoKluczu[$klucz] = SkladnikOdzywczy::query()->updateOrCreate(['klucz' => $klucz], $dane)->getKey();
            }

            // Aliasy i miary są w całości pochodną pliku: kasujemy i piszemy
            // od nowa, zamiast synchronizować wiersz po wierszu.
            AliasSkladnika::query()->delete();
            MiaraDomowa::query()->delete();

            foreach (array_chunk($this->zId($aliasy, $idPoKluczu, 'alias'), 500) as $paczka) {
                AliasSkladnika::query()->insert($paczka);
            }
            foreach (array_chunk($this->zId($miaryDoZapisu, $idPoKluczu, 'jednostka'), 500) as $paczka) {
                MiaraDomowa::query()->insert($paczka);
            }

            return [
                'skladniki' => count($pozycje),
                'aliasy' => count($aliasy),
                'miary' => count($miaryDoZapisu),
                'usuniete' => (int) $usuniete,
            ];
        });
    }

    /**
     * @param  list<string>  $kolumny
     * @return list<array<string, string>> wiersze z numerem linii pod kluczem `_linia`
     */
    private function czytajCsv(string $sciezka, array $kolumny): array
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException("Nie ma pliku {$sciezka}.");
        }

        $uchwyt = fopen($sciezka, 'r');
        if ($uchwyt === false) {
            throw new RuntimeException("Nie da się otworzyć {$sciezka}.");
        }

        $naglowek = fgetcsv($uchwyt, escape: '');
        if ($naglowek !== $kolumny) {
            fclose($uchwyt);
            throw new RuntimeException(basename($sciezka).': nagłówek ma być „'.implode(',', $kolumny).'”.');
        }

        $wiersze = [];
        $linia = 1;
        while (($wiersz = fgetcsv($uchwyt, escape: '')) !== false) {
            $linia++;
            if ($wiersz === [null]) {
                continue;
            }
            if (count($wiersz) !== count($kolumny)) {
                fclose($uchwyt);
                throw new RuntimeException(basename($sciezka).", wiersz {$linia}: zła liczba kolumn.");
            }
            $wiersze[] = array_combine($kolumny, array_map('strval', $wiersz)) + ['_linia' => (string) $linia];
        }
        fclose($uchwyt);

        return $wiersze;
    }

    /**
     * @param  list<array<string, string>>  $wiersze
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    private function sprawdzSkladniki(array $wiersze): array
    {
        $pozycje = [];
        $aliasy = [];

        foreach ($wiersze as $w) {
            $gdzie = "skladniki.csv, wiersz {$w['_linia']}";
            $klucz = $w['klucz'];

            if (preg_match('/^[a-z0-9_]{1,80}$/', $klucz) !== 1) {
                throw new RuntimeException("{$gdzie}: klucz „{$klucz}” może mieć tylko małe litery, cyfry i podkreślnik.");
            }
            if (isset($pozycje[$klucz])) {
                throw new RuntimeException("{$gdzie}: klucz „{$klucz}” jest drugi raz.");
            }
            if (! in_array($w['zrodlo'], [SkladnikOdzywczy::ZRODLO_CIQUAL, SkladnikOdzywczy::ZRODLO_USDA], true)) {
                throw new RuntimeException("{$gdzie}: źródło ma być „ciqual” albo „usda”.");
            }

            $wartosci = [];
            foreach (['kcal' => 950, 'bialko' => 100, 'tluszcz' => 100, 'weglowodany' => 100] as $pole => $max) {
                if (! is_numeric($w[$pole]) || (float) $w[$pole] < 0 || (float) $w[$pole] > $max) {
                    throw new RuntimeException("{$gdzie}: {$pole} ma być liczbą od 0 do {$max}.");
                }
                $wartosci[$pole] = (float) $w[$pole];
            }

            $gestosc = null;
            if ($w['gestosc_g_ml'] !== '') {
                if (! is_numeric($w['gestosc_g_ml']) || (float) $w['gestosc_g_ml'] <= 0 || (float) $w['gestosc_g_ml'] >= 3) {
                    throw new RuntimeException("{$gdzie}: gęstość ma być liczbą większą od 0 i mniejszą od 3 albo pusta.");
                }
                $gestosc = (float) $w['gestosc_g_ml'];
            }

            $pozycje[$klucz] = [
                'nazwa' => $w['nazwa'],
                'zrodlo' => $w['zrodlo'],
                'zrodlo_id' => $w['zrodlo_id'],
                'zrodlo_nazwa' => mb_substr($w['zrodlo_nazwa'], 0, 200),
                'kcal_100g' => $wartosci['kcal'],
                'bialko_100g' => $wartosci['bialko'],
                'tluszcz_100g' => $wartosci['tluszcz'],
                'weglowodany_100g' => $wartosci['weglowodany'],
                'gestosc_g_ml' => $gestosc,
                'pomijalny' => $w['pomijalny'] === '1',
            ];

            foreach (explode('|', $w['aliasy']) as $alias) {
                $normalny = SlownikSkladnikow::normalizujAlias($alias);
                if ($normalny === '') {
                    continue;
                }
                // „mąka” i „mąką” po normalizacji to to samo słowo — w obrębie
                // jednej pozycji to nie błąd. Ten sam alias przy DWÓCH
                // pozycjach jest błędem: słownik nie wiedziałby, którą wybrać.
                if (isset($aliasy[$normalny]) && $aliasy[$normalny] !== $klucz) {
                    throw new RuntimeException("{$gdzie}: nazwa „{$alias}” jest już przy „{$aliasy[$normalny]}”.");
                }
                $aliasy[$normalny] = $klucz;
            }
        }

        return [$pozycje, $aliasy];
    }

    /**
     * @param  list<array<string, string>>  $wiersze
     * @param  array<string, array<string, mixed>>  $pozycje
     * @return array<string, array{klucz: string, jednostka: string, gramy: float, uwagi: string|null}> "klucz|jednostka" → miara
     */
    private function sprawdzMiary(array $wiersze, array $pozycje): array
    {
        $miary = [];

        foreach ($wiersze as $w) {
            $gdzie = "miary.csv, wiersz {$w['_linia']}";
            if (! isset($pozycje[$w['klucz']])) {
                throw new RuntimeException("{$gdzie}: nie ma składnika „{$w['klucz']}” w skladniki.csv.");
            }
            if (preg_match('/^[a-z]{1,30}$/', $w['jednostka']) !== 1) {
                throw new RuntimeException("{$gdzie}: jednostka ma być słowem z małych liter bez ogonków.");
            }
            if (! is_numeric($w['gramy']) || (float) $w['gramy'] <= 0 || (float) $w['gramy'] > 10000) {
                throw new RuntimeException("{$gdzie}: gramy mają być liczbą od 0 do 10 000.");
            }
            $id = $w['klucz'].'|'.$w['jednostka'];
            if (isset($miary[$id])) {
                throw new RuntimeException("{$gdzie}: miara „{$w['jednostka']}” dla „{$w['klucz']}” jest drugi raz.");
            }
            $miary[$id] = ['klucz' => $w['klucz'], 'jednostka' => $w['jednostka'], 'gramy' => (float) $w['gramy'], 'uwagi' => $w['uwagi'] !== '' ? mb_substr($w['uwagi'], 0, 200) : null];
        }

        return $miary;
    }

    /**
     * @param  array<string, mixed>  $wiersze
     * @param  array<string, string>  $idPoKluczu
     * @return list<array<string, mixed>>
     */
    private function zId(array $wiersze, array $idPoKluczu, string $rodzaj): array
    {
        $wynik = [];
        foreach ($wiersze as $klucz => $wartosc) {
            if ($rodzaj === 'alias') {
                $wynik[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'alias' => $klucz, 'skladnik_odzywczy_id' => $idPoKluczu[$wartosc]];
            } else {
                $wynik[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'skladnik_odzywczy_id' => $idPoKluczu[$wartosc['klucz']], 'jednostka' => $wartosc['jednostka'], 'gramy' => $wartosc['gramy'], 'uwagi' => $wartosc['uwagi']];
            }
        }

        return $wynik;
    }
}
