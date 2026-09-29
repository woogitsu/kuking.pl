<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\SkladnikOdzywczy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * pracą liczymy hash źródeł i porównujemy go ze znacznikiem ostatniego
 * udanego importu. Znacznik zawiera też odcisk zawartości TRZECH tabel.
 * Sam hash plików nie dowodzi kompletności bazy po częściowym restore
 * (#2130). Przy niezgodności odbudowujemy słownik; nowy znacznik zapisujemy
 * na końcu tej samej transakcji, pod tą samą blokadą co słowniki.
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

        $znacznik = $wymus ? null : Cache::get(self::CACHE_KLUCZ);
        if (! $wymus && $hash !== null && is_array($znacznik) && ($znacznik['hash'] ?? null) === $hash) {
            $stan = $this->stanBazy();
            if ($stan['skladniki'] > 0 && ($znacznik['odcisk'] ?? null) === $stan['odcisk']) {
                return [
                    'skladniki' => $stan['skladniki'],
                    'aliasy' => $stan['aliasy'],
                    'miary' => $stan['miary'],
                    'usuniete' => 0,
                    'pominieto' => true,
                ];
            }

            Log::warning('Słownik wartości odżywczych różni się od udanego importu — odbudowuję go.', [
                'oczekiwane' => $znacznik['licznosci'] ?? null,
                'obecne' => [$stan['skladniki'], $stan['aliasy'], $stan['miary']],
            ]);
        } elseif (! $wymus && $hash !== null && $znacznik === $hash) {
            Log::warning('Stary znacznik importu nie potwierdza trzech tabel — odbudowuję słownik.');
        }

        $skladniki = $this->czytajCsv($sciezkaSkladnikow, self::KOLUMNY_SKLADNIKOW);
        $miary = $this->czytajCsv($sciezkaMiar, self::KOLUMNY_MIAR);

        // Plik ucięty do samego nagłówka jest poprawnym CSV, ale import
        // skasowałby cały słownik (usuwa wszystko, czego nie ma w pliku).
        if ($skladniki === [] || $miary === []) {
            throw new BladDlaCzlowieka('skladniki.csv i miary.csv muszą mieć co najmniej jeden wiersz z danymi — plik wygląda na ucięty, a słownik został bez zmian. Przywróć oba pliki z repozytorium (git checkout -- database/data/odzywcze) i uruchom komendę ponownie.');
        }

        [$pozycje, $aliasy] = $this->sprawdzSkladniki($skladniki);
        $miaryDoZapisu = $this->sprawdzMiary($miary, $pozycje);

        return DB::transaction(function () use ($pozycje, $aliasy, $miaryDoZapisu, $hash): array {
            // Dwie instancje pre-deploy nie mogą jednocześnie usuwać i pisać
            // słowników, nawet gdy obie zobaczyły stary znacznik cache.
            DB::selectOne('SELECT pg_advisory_xact_lock(2130, 0)');
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

            // Znacznik zapisujemy PO ostatnim zapisie słowników, ale jeszcze
            // POD TĄ SAMĄ blokadą i w tej samej transakcji (#2130). Zapis po
            // COMMIT-cie, już bez blokady, pozwalał wolniejszej instancji
            // dopisać znacznik starszego wyniku po tym, jak inna instancja
            // zdążyła zatwierdzić i zapisać własny — tabele opisywał wtedy
            // cudzy hash. Przy magazynie `database` (produkcja) zapis znacznika
            // jest częścią tej transakcji: wycofanie cofa też znacznik, a
            // kolejność zapisów znaczników jest kolejnością blokady. Przy innym
            // magazynie znacznik i tak jest tylko optymalizacją — szybka ścieżka
            // porównuje go z odciskiem tabel, więc rozjazd kończy się odbudową,
            // nigdy fałszywym „już zrobione”.
            $stanPo = $this->stanBazy();
            if ($hash !== null) {
                Cache::forever(self::CACHE_KLUCZ, [
                    'hash' => $hash,
                    'odcisk' => $stanPo['odcisk'],
                    'licznosci' => [$stanPo['skladniki'], $stanPo['aliasy'], $stanPo['miary']],
                ]);
            }

            return [
                'skladniki' => count($pozycje),
                'aliasy' => count($aliasy),
                'miary' => count($miaryDoZapisu),
                'usuniete' => (int) $usuniete,
                'pominieto' => false,
            ];
        });
    }

    /** Hash danych i kodu normalizacji albo null, gdy czegoś nie da się przeczytać. */
    private function hashPlikow(string $sciezkaSkladnikow, string $sciezkaMiar): ?string
    {
        $sciezki = [$sciezkaSkladnikow, $sciezkaMiar, __FILE__, __DIR__.'/SlownikSkladnikow.php', __DIR__.'/ParserSkladnika.php'];
        $tresci = [];
        foreach ($sciezki as $sciezka) {
            if (! is_readable($sciezka)) {
                return null;
            }
            $tresc = file_get_contents($sciezka);
            if ($tresc === false) {
                return null;
            }
            $tresci[] = $tresc;
        }

        return hash('sha256', implode('|', $tresci));
    }

    /**
     * Odcisk wartości słowników, bez UUID wierszy: ponowny import może
     * wygenerować nowe UUID aliasów i miar przy identycznych danych.
     *
     * @return array{skladniki: int, aliasy: int, miary: int, odcisk: string}
     */
    private function stanBazy(): array
    {
        $skladniki = DB::table('skladniki_odzywcze')
            ->orderBy('klucz')
            ->get(['klucz', 'nazwa', 'zrodlo', 'zrodlo_id', 'zrodlo_nazwa', 'kcal_100g', 'bialko_100g', 'tluszcz_100g', 'weglowodany_100g', 'gestosc_g_ml', 'pomijalny']);
        $aliasy = DB::table('aliasy_skladnikow as a')
            ->join('skladniki_odzywcze as s', 's.id', '=', 'a.skladnik_odzywczy_id')
            ->orderBy('a.alias')
            ->get(['a.alias', 's.klucz as skladnik']);
        $miary = DB::table('miary_domowe as m')
            ->join('skladniki_odzywcze as s', 's.id', '=', 'm.skladnik_odzywczy_id')
            ->orderBy('s.klucz')
            ->orderBy('m.jednostka')
            ->get(['s.klucz as skladnik', 'm.jednostka', 'm.gramy', 'm.uwagi']);

        return [
            'skladniki' => $skladniki->count(),
            'aliasy' => $aliasy->count(),
            'miary' => $miary->count(),
            'odcisk' => hash('sha256', json_encode([$skladniki, $aliasy, $miary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
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
