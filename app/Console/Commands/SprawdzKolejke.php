<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use Illuminate\Console\Command;

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

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        return $wynik['stan'] === StanKolejki::SPOKOJNA ? self::SUCCESS : self::FAILURE;
    }
}
