<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Czujka: czy kolejka jeszcze pracuje i czy coś padło niedawno (issue #599).
 *
 * DLACZEGO TO NIE JEST TO SAMO, CO POLE `kolejka` W `/health`
 * Tamto liczy WSZYSTKIE wiersze w `failed_jobs` i przy liczbie większej od
 * zera stawia `degraded`. Na produkcji leżą cztery zadania z 9 września 2026,
 * więc `/health` jest w `degraded` nieprzerwanie od tamtego dnia — a sygnał,
 * który świeci zawsze, nie odróżni piątej awarii od czwartej. Ta komenda
 * pyta o ZDARZENIE („co padło w ostatnich godzinach") i o rzecz, której
 * `/health` nie mierzy wcale: jak długo czeka najstarsze gotowe zadanie.
 *
 * Kod wyjścia jest NIEZEROWY przy zaległości i przy nowych nieudanych —
 * żeby uruchomienie ręczne dało się wpleść w skrypt, a nie tylko przeczytać.
 */
class SprawdzKolejke extends Command
{
    protected $signature = 'kuking:sprawdz-kolejke
                            {--bez-alarmu : Sprawdź i wypisz stan, ale nie dzwoń na webhook}';

    protected $description = 'Sprawdza zaległość kolejki i świeże nieudane zadania, i alarmuje, gdy worker stoi (issue #599).';

    public function handle(StanKolejki $stan, AlarmKolejki $alarm): int
    {
        $wynik = $stan->sprawdz();

        if ($wynik['stan'] === StanKolejki::NIEDOSTEPNA) {
            $this->error('Nie udało się odczytać stanu tabel kolejki.');

            if (! $this->option('bez-alarmu')) {
                $alarm->zadzwonJesliTrzeba($wynik);
            }

            return self::FAILURE;
        }

        $this->table(['Co', 'Wartość'], [
            ['zadania gotowe do wzięcia', (string) $wynik['oczekujace']],
            ['zaległość najstarszego (s)', (string) $wynik['zaleglosc_sekundy']],
            ['próg zaległości (s)', (string) $wynik['prog_zaleglosci_sekundy']],
            ['zawieszone rezerwacje', (string) $wynik['zawieszone']],
            ['próg zawieszenia (s)', (string) $wynik['prog_zawieszenia_sekundy']],
            ['nieudane w oknie', (string) $wynik['nieudane_w_oknie']],
            ['okno (h)', (string) $wynik['okno_godzin']],
            ['nieudane razem', (string) $wynik['nieudane_razem']],
            ...array_map(
                fn (string $nazwa, array $liczby) => [
                    "kolejka {$nazwa}: gotowe / zaległość (s)",
                    $liczby['oczekujace'].' / '.$liczby['zaleglosc_sekundy'],
                ],
                array_keys($wynik['kolejki']),
                $wynik['kolejki'],
            ),
        ]);

        match ($wynik['stan']) {
            StanKolejki::SPOKOJNA => $this->info(sprintf(
                'Kolejka pracuje: nic nie padło w ostatnich %d h, najstarsze gotowe zadanie czeka %d s. '
                .'(W tabeli leży łącznie %d starych nieudanych — to osobna sprawa, nie awaria z dziś.)',
                (int) $wynik['okno_godzin'],
                (int) $wynik['zaleglosc_sekundy'],
                (int) $wynik['nieudane_razem'],
            )),
            StanKolejki::ZALEGLOSC => $this->error(sprintf(
                'Najstarsze gotowe zadanie czeka %d s przy progu %d s (oczekujących %d, zawieszonych %d). '
                .'Worker prawdopodobnie NIE PRACUJE — a taki worker nie zgłasza żadnego błędu.',
                (int) $wynik['zaleglosc_sekundy'],
                (int) $wynik['prog_zaleglosci_sekundy'],
                (int) $wynik['oczekujace'],
                (int) $wynik['zawieszone'],
            )),
            StanKolejki::NOWE_NIEUDANE => $this->error(sprintf(
                'W ostatnich %d h padło %d zadań. Co to jest i kogo dotyczy: `php artisan kuking:martwe-zadania`.',
                (int) $wynik['okno_godzin'],
                (int) $wynik['nieudane_w_oknie'],
            )),
            default => $this->error('Nieznany stan kolejki.'),
        };

        $kolejki = $stan->poKolejkach();

        if ($kolejki !== null) {
            $this->table(
                ['kolejka', 'gotowe', 'zaległość (s)', 'zawieszone', 'nieudane w oknie'],
                array_map(
                    fn (string $nazwa, array $k): array => [$nazwa, $k['oczekujace'], $k['zaleglosc_sekundy'], $k['zawieszone'], $k['nieudane_w_oknie']],
                    array_keys($kolejki),
                    $kolejki,
                ),
            );
        }

        $this->zapiszWDzienniku($wynik, $kolejki);

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        return $wynik['stan'] === StanKolejki::SPOKOJNA ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Jedna linia pomiaru do dziennika serwera — z tego samego powodu, co
     * w `BudzetPolaczen`: `Schedule::call()` woła komendę przez
     * `Artisan::call()`, które przechwytuje wyjście konsoli, więc bez tego
     * wpisu harmonogram mierzy i natychmiast zapomina.
     *
     * Tutaj waży to nawet więcej niż przy połączeniach. Alarm mówi dopiero
     * wtedy, gdy zaległość PRZEKROCZY próg — a pytanie, na które nikt dziś
     * nie umie odpowiedzieć, brzmi „ile ta kolejka zwykle ma zaległości".
     * Bez szeregu czasowego próg 600 s jest liczbą wziętą z rozumowania,
     * nie z obserwacji, i nie da się go uczciwie poprawić.
     *
     * Co 15 minut daje 96 linii na dobę. To jest cena, którą świadomie
     * płacimy za jedyny dostępny szereg czasowy kolejki: do produkcyjnego
     * Postgresa nie ma dostępu z zewnątrz.
     *
     * CZEGO W TEJ LINII NIE MA: `payload`, `exception`, adresów odbiorców
     * ani nazw klas zadań. Same liczby, nazwa stanu i nazwy kolejek (#1030).
     *
     * KANAŁ `pomiary`, A NIE ZWYKŁE `Log::info()` — ta sama poprawka, co
     * w `BudzetPolaczen`: zwykłe `info` szło kanałem `stderr`, a ten bierze
     * poziom z `LOG_LEVEL`, ustawionego na produkcji na `warning`
     * (`.railway/railway.ts`). Cena „96 linii na dobę" była więc płacona
     * za szereg, który w ogóle nie powstawał. Uzasadnienie kanału stoi
     * w `config/logging.php`.
     *
     * `kolejki` to rozbicie tych samych liczb na `high`/`default`/`media`/`low`
     * (issue #599: „osobna widoczność media vs lżejsze kolejki") — same
     * liczby i nazwy ze stałego słownika `StanKolejki::ZNANE_KOLEJKI`.
     *
     * @param  array<string, mixed>  $wynik
     * @param  array<string, array<string, int>>|null  $kolejki
     */
    private function zapiszWDzienniku(array $wynik, ?array $kolejki = null): void
    {
        Log::channel('pomiary')->info('kuking:sprawdz-kolejke', [
            'stan' => $wynik['stan'],
            'oczekujace' => $wynik['oczekujace'],
            'zaleglosc_sekundy' => $wynik['zaleglosc_sekundy'],
            'zawieszone' => $wynik['zawieszone'],
            'nieudane_w_oknie' => $wynik['nieudane_w_oknie'],
            'nieudane_razem' => $wynik['nieudane_razem'],
            'prog_zaleglosci_sekundy' => $wynik['prog_zaleglosci_sekundy'],
            'najstarsza_kolejka' => $wynik['najstarsza_kolejka'],
            // Pełne rozbicie z `poKolejkach()` (#599); `najstarsza_kolejka`
            // i skrót w tabeli wyżej pochodzą z `sprawdz()` (#1030).
            'kolejki' => $kolejki,
        ]);
    }
}
