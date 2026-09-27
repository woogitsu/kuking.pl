<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 *
 * SZYBKIE POMIJANIE PRZY WDROŻENIU (#1961). Komenda wchodzi teraz do
 * `preDeployCommand` obok migracji i `db:seed`, więc leci przy KAŻDYM
 * wdrożeniu, nie tylko wtedy, gdy pliki się zmieniły. Parsowanie
 * ~600 wierszy i przepisanie trzech tabel w transakcji przy każdym
 * deployu byłoby zbędnym kosztem czasu release'u, więc przed jakąkolwiek
 * pracą liczymy hash zawartości obu plików CSV i porównujemy go z hashem
 * zapisanym po poprzednim udanym imporcie (`Cache`, sterownik `database`
 * — bez Redisa). Ten sam hash i choć jeden wiersz w tabeli → pomijamy
 * całość i zwracamy bieżący stan bazy. Inny hash, pusta tabela albo
 * `$wymus === true` → import leci normalnie i zapisuje nowy hash dopiero
 * PO udanej transakcji (błąd importu nie ma prawa uśpić następnego razu).
 */
final class ImportujWartosciOdzywcze
{
    public const KATALOG = 'database/data/odzywcze';

    private const CACHE_KLUCZ = 'odzywcze:import:hash-plikow';

    private const KOLUMNY_SKLADNIKOW = ['klucz', 'nazwa', 'aliasy', 'zrodlo', 'zrodlo_id', 'gestosc_g_ml', 'pomijalny', 'zrodlo_nazwa', 'kcal', 'bialko', 'tluszcz', 'weglowodany'];

    private const KOLUMNY_MIAR = ['klucz', 'jednostka', 'gramy', 'uwagi'];

    /**
     * @return array{skladniki: int, aliasy: int, miary: int, usuniete: int, pominieto: bool}
     *
     * @throws BladDlaCzlowieka gdy plik jest niepoprawny (treść jest dla osoby uruchamiającej komendę) — z numerem wiersza
     */
    public function handle(?string $katalog = null, bool $wymus = false): array
    {
        $katalog ??= base_path(self::KATALOG);
        $sciezkaSkladnikow = $katalog.'/skladniki.csv';
        $sciezkaMiar = $katalog.'/miary.csv';

        $hash = $this->hashPlikow($sciezkaSkladnikow, $sciezkaMiar);

        if (! $wymus && $hash !== null && Cache::get(self::CACHE_KLUCZ) === $hash && SkladnikOdzywczy::query()->exists()) {
            return [
                'skladniki' => SkladnikOdzywczy::query()->count(),
                'aliasy' => AliasSkladnika::query()->count(),
                'miary' => MiaraDomowa::query()->count(),
                'usuniete' => 0,
                'pominieto' => true,
            ];
        }

        $skladniki = $this->czytajCsv($sciezkaSkladnikow, self::KOLUMNY_SKLADNIKOW);
        $miary = $this->czytajCsv($sciezkaMiar, self::KOLUMNY_MIAR);

        [$pozycje, $aliasy] = $this->sprawdzSkladniki($skladniki);
        $miaryDoZapisu = $this->sprawdzMiary($miary, $pozycje);

        $wynik = DB::transaction(function () use ($pozycje, $aliasy, $miaryDoZapisu): array {
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
                'pominieto' => false,
            ];
        });

        // Hash zapisujemy DOPIERO PO udanej transakcji — błąd w połowie
        // importu (rzucony wyżej jako BladDlaCzlowieka albo wyjątek bazy)
        // nie ma prawa uśpić kolejnego uruchomienia fałszywym „już zrobione”.
        if ($hash !== null) {
            Cache::forever(self::CACHE_KLUCZ, $hash);
        }

        return $wynik;
    }

    /** Hash zawartości obu plików razem albo null, gdy któregoś nie da się przeczytać. */
    private function hashPlikow(string $sciezkaSkladnikow, string $sciezkaMiar): ?string
    {
        if (! is_readable($sciezkaSkladnikow) || ! is_readable($sciezkaMiar)) {
            return null;
        }

        $tresc = file_get_contents($sciezkaSkladnikow);
        $tresc2 = file_get_contents($sciezkaMiar);
        if ($tresc === false || $tresc2 === false) {
            return null;
        }

        return hash('sha256', $tresc.'|'.$tresc2);
    }

    /**
     * @param  list<string>  $kolumny
     * @return list<array<string, string>> wiersze z numerem linii pod kluczem `_linia`
     */
    private function czytajCsv(string $sciezka, array $kolumny): array
    {
        if (! is_readable($sciezka)) {
            throw new BladDlaCzlowieka("Nie ma pliku {$sciezka}.");
        }

        $uchwyt = fopen($sciezka, 'r');
        if ($uchwyt === false) {
            throw new BladDlaCzlowieka("Nie da się otworzyć {$sciezka}.");
        }

        $naglowek = fgetcsv($uchwyt, escape: '');
        if ($naglowek !== $kolumny) {
            fclose($uchwyt);
            throw new BladDlaCzlowieka(basename($sciezka).': nagłówek ma być „'.implode(',', $kolumny).'”.');
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
                throw new BladDlaCzlowieka(basename($sciezka).", wiersz {$linia}: zła liczba kolumn.");
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
                throw new BladDlaCzlowieka("{$gdzie}: klucz „{$klucz}” może mieć tylko małe litery, cyfry i podkreślnik.");
            }
            if (isset($pozycje[$klucz])) {
                throw new BladDlaCzlowieka("{$gdzie}: klucz „{$klucz}” jest drugi raz.");
            }
            if (! in_array($w['zrodlo'], [SkladnikOdzywczy::ZRODLO_CIQUAL, SkladnikOdzywczy::ZRODLO_USDA], true)) {
                throw new BladDlaCzlowieka("{$gdzie}: źródło ma być „ciqual” albo „usda”.");
            }

            $wartosci = [];
            foreach (['kcal' => 950, 'bialko' => 100, 'tluszcz' => 100, 'weglowodany' => 100] as $pole => $max) {
                if (! is_numeric($w[$pole]) || (float) $w[$pole] < 0 || (float) $w[$pole] > $max) {
                    throw new BladDlaCzlowieka("{$gdzie}: {$pole} ma być liczbą od 0 do {$max}.");
                }
                $wartosci[$pole] = (float) $w[$pole];
            }

            $gestosc = null;
            if ($w['gestosc_g_ml'] !== '') {
                if (! is_numeric($w['gestosc_g_ml']) || (float) $w['gestosc_g_ml'] <= 0 || (float) $w['gestosc_g_ml'] >= 3) {
                    throw new BladDlaCzlowieka("{$gdzie}: gęstość ma być liczbą większą od 0 i mniejszą od 3 albo pusta.");
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
                    throw new BladDlaCzlowieka("{$gdzie}: nazwa „{$alias}” jest już przy „{$aliasy[$normalny]}”.");
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
                throw new BladDlaCzlowieka("{$gdzie}: nie ma składnika „{$w['klucz']}” w skladniki.csv.");
            }
            if (preg_match('/^[a-z]{1,30}$/', $w['jednostka']) !== 1) {
                throw new BladDlaCzlowieka("{$gdzie}: jednostka ma być słowem z małych liter bez ogonków.");
            }
            if (! is_numeric($w['gramy']) || (float) $w['gramy'] <= 0 || (float) $w['gramy'] > 10000) {
                throw new BladDlaCzlowieka("{$gdzie}: gramy mają być liczbą od 0 do 10 000.");
            }
            $id = $w['klucz'].'|'.$w['jednostka'];
            if (isset($miary[$id])) {
                throw new BladDlaCzlowieka("{$gdzie}: miara „{$w['jednostka']}” dla „{$w['klucz']}” jest drugi raz.");
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
                $wynik[] = ['id' => (string) Str::uuid(), 'alias' => $klucz, 'skladnik_odzywczy_id' => $idPoKluczu[$wartosc]];
            } else {
                $wynik[] = ['id' => (string) Str::uuid(), 'skladnik_odzywczy_id' => $idPoKluczu[$wartosc['klucz']], 'jednostka' => $wartosc['jednostka'], 'gramy' => $wartosc['gramy'], 'uwagi' => $wartosc['uwagi']];
            }
        }

        return $wynik;
    }
}
