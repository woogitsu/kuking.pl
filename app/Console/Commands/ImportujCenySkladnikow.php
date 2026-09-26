<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CenaSkladnika;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wczytuje cennik składników z pliku CSV w repozytorium (D-286, część 2).
 *
 * NICZEGO NIE POBIERA Z SIECI. Ceny przychodzą do repozytorium jako plik
 * `database/data/ceny_skladnikow.csv` — odświeżany raz na kwartał na
 * komputerze osoby prowadzącej (`scripts/ceny-gus-pobierz.py`, API Banku
 * Danych Lokalnych GUS), przeglądany w PR-ze jak każda zmiana. Produkcja
 * tylko wczytuje to, co przeszło przegląd.
 *
 * Wszystko albo nic: plik jest sprawdzany w całości, zanim cokolwiek
 * trafi do bazy, a zapis idzie w jednej transakcji (wiersze spoza pliku
 * znikają, bo plik jest pełnym cennikiem, nie łatką). Ponowne uruchomienie
 * na tym samym pliku daje ten sam stan.
 */
class ImportujCenySkladnikow extends Command
{
    protected $signature = 'kuking:ceny-skladnikow
                            {--plik= : Ścieżka do pliku CSV (domyślnie database/data/ceny_skladnikow.csv)}
                            {--sprawdz : Tylko sprawdź plik, niczego nie zapisuj}';

    protected $description = 'Wczytuje cennik składników (średnie ceny GUS) z pliku CSV do tabeli ceny_skladnikow';

    /** @var list<string> */
    private const KOLUMNY = ['klucz', 'nazwa', 'wzorce', 'wyklucz', 'cena_zl', 'za_ilosc', 'jednostka', 'g_na_jednostke',
        'g_szklanka', 'g_lyzka', 'g_lyzeczka', 'g_sztuka', 'okres', 'zrodlo', 'zmienna_bdl'];

    public function handle(): int
    {
        $plik = (string) ($this->option('plik') ?: base_path('database/data/ceny_skladnikow.csv'));

        if (! is_file($plik) || ! is_readable($plik)) {
            $this->error("Nie ma pliku {$plik}. Podaj ścieżkę opcją --plik.");

            return self::FAILURE;
        }

        [$wiersze, $bledy] = $this->wczytaj($plik);

        if ($bledy !== []) {
            foreach ($bledy as $blad) {
                $this->error($blad);
            }
            $this->error('Niczego nie zapisano. Popraw plik i uruchom komendę jeszcze raz.');

            return self::FAILURE;
        }

        if ($this->option('sprawdz')) {
            $this->info('Plik jest poprawny: '.count($wiersze).' wierszy. Niczego nie zapisano (--sprawdz).');

            return self::SUCCESS;
        }

        $teraz = now();

        DB::transaction(function () use ($wiersze, $teraz): void {
            CenaSkladnika::query()->whereNotIn('klucz', array_column($wiersze, 'klucz'))->delete();

            foreach ($wiersze as $wiersz) {
                CenaSkladnika::query()->updateOrCreate(
                    ['klucz' => $wiersz['klucz']],
                    [...$wiersz, 'zaimportowano_at' => $teraz],
                );
            }
        });

        $this->info('Wczytano '.count($wiersze).' wierszy cennika z '.$plik.'.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function wczytaj(string $plik): array
    {
        $uchwyt = fopen($plik, 'r');
        if ($uchwyt === false) {
            return [[], ["Nie da się otworzyć pliku {$plik}."]];
        }

        $naglowek = fgetcsv($uchwyt, escape: '');
        if ($naglowek !== self::KOLUMNY) {
            fclose($uchwyt);

            return [[], ['Nagłówek pliku musi brzmieć dokładnie: '.implode(',', self::KOLUMNY).'.']];
        }

        $wiersze = [];
        $bledy = [];
        $klucze = [];
        $numer = 1;

        while (($pola = fgetcsv($uchwyt, escape: '')) !== false) {
            $numer++;

            if ($pola === [null] || $pola === ['']) {
                continue;
            }

            if (count($pola) !== count(self::KOLUMNY)) {
                $bledy[] = "Wiersz {$numer}: oczekiwano ".count(self::KOLUMNY).' kolumn, jest '.count($pola).'.';

                continue;
            }

            /** @var array<string, string> $w */
            $w = array_combine(self::KOLUMNY, array_map(static fn ($p): string => trim((string) $p), $pola));
            $blad = $this->sprawdz($w, $klucze);

            if ($blad !== null) {
                $bledy[] = "Wiersz {$numer} ({$w['klucz']}): {$blad}";

                continue;
            }

            $klucze[] = $w['klucz'];
            $wiersze[] = [
                'klucz' => $w['klucz'],
                'nazwa' => $w['nazwa'],
                'wzorce' => $w['wzorce'],
                'wyklucz' => $w['wyklucz'] === '' ? null : $w['wyklucz'],
                'cena_zl' => (float) $w['cena_zl'],
                'za_ilosc' => (float) $w['za_ilosc'],
                'jednostka' => $w['jednostka'],
                'g_na_jednostke' => (float) $w['g_na_jednostke'],
                'g_szklanka' => $this->liczbaAlboNull($w['g_szklanka']),
                'g_lyzka' => $this->liczbaAlboNull($w['g_lyzka']),
                'g_lyzeczka' => $this->liczbaAlboNull($w['g_lyzeczka']),
                'g_sztuka' => $this->liczbaAlboNull($w['g_sztuka']),
                'kolejnosc' => count($wiersze) + 1,
                'okres' => $w['okres'],
                'zrodlo' => $w['zrodlo'],
                'zmienna_bdl' => $w['zmienna_bdl'] === '' ? null : $w['zmienna_bdl'],
            ];
        }

        fclose($uchwyt);

        if ($wiersze === [] && $bledy === []) {
            $bledy[] = 'Plik nie ma ani jednego wiersza cennika. Pusty plik skasowałby cały cennik — to na pewno pomyłka.';
        }

        return [$wiersze, $bledy];
    }

    /**
     * @param  array<string, string>  $w
     * @param  list<string>  $klucze
     */
    private function sprawdz(array $w, array $klucze): ?string
    {
        return match (true) {
            preg_match('/^[a-z0-9_]{1,60}$/', $w['klucz']) !== 1 => 'klucz może mieć tylko małe litery bez polskich znaków, cyfry i podkreślnik (do 60 znaków).',
            in_array($w['klucz'], $klucze, true) => 'ten klucz już był wyżej w pliku.',
            $w['nazwa'] === '' || mb_strlen($w['nazwa']) > 160 => 'nazwa jest pusta albo dłuższa niż 160 znaków.',
            preg_match('/^[a-z |]+$/', $w['wzorce']) !== 1 => 'wzorce to formy słów małymi literami bez polskich znaków, rozdzielone „|”.',
            $w['wyklucz'] !== '' && preg_match('/^[a-z |]+$/', $w['wyklucz']) !== 1 => 'wyklucz to początki słów małymi literami bez polskich znaków, rozdzielone „|”.',
            ! $this->liczba($w['cena_zl'], dopuscZero: true) => 'cena_zl musi być liczbą ≥ 0 z kropką, np. 3.76.',
            ! $this->liczba($w['za_ilosc']) => 'za_ilosc musi być liczbą > 0, np. 0.5.',
            ! in_array($w['jednostka'], ['kg', 'l', 'szt'], true) => 'jednostka to kg, l albo szt.',
            ! $this->liczba($w['g_na_jednostke']) => 'g_na_jednostke musi być liczbą > 0.',
            ! $this->pustaAlboLiczba($w['g_szklanka']) || ! $this->pustaAlboLiczba($w['g_lyzka'])
                || ! $this->pustaAlboLiczba($w['g_lyzeczka']) || ! $this->pustaAlboLiczba($w['g_sztuka']) => 'miary domowe (g_*) są puste albo są liczbą > 0.',
            $w['okres'] === '' || mb_strlen($w['okres']) > 40 => 'okres jest pusty albo dłuższy niż 40 znaków.',
            $w['zrodlo'] === '' || mb_strlen($w['zrodlo']) > 240 => 'zrodlo jest puste albo dłuższe niż 240 znaków — każda cena musi mówić, skąd jest.',
            default => null,
        };
    }

    private function liczba(string $wartosc, bool $dopuscZero = false): bool
    {
        if (preg_match('/^\d+(\.\d+)?$/', $wartosc) !== 1) {
            return false;
        }

        return $dopuscZero ? (float) $wartosc >= 0 : (float) $wartosc > 0;
    }

    private function pustaAlboLiczba(string $wartosc): bool
    {
        return $wartosc === '' || $this->liczba($wartosc);
    }

    private function liczbaAlboNull(string $wartosc): ?float
    {
        return $wartosc === '' ? null : (float) $wartosc;
    }
}
