<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Czas;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regresja #1330: tygodniowe podsumowanie nie może wychodzić w ciszy nocnej
 * (21:00–08:00, `docs/product/RETENTION_LOOPS.md` §3.2).
 *
 * `ObietnicaTygodniowegoMailaTest` sprawdzał, że zadanie ISTNIEJE, ale nie
 * KIEDY startuje — `dailyAt('03:30')` zostawiało wszystkie testy zielone.
 *
 * Test czyta prawdziwe zdarzenie z `Schedule::events()`, nie tekst
 * `routes/console.php`, więc nie potrzebuje wpisu w
 * `scripts/kontrole-negatywne-alfa08.py`. Cisza nocna jest ciszą CZŁOWIEKA,
 * więc okno liczymy w `Czas::strefa()` (Europe/Warsaw), a terminy — w strefie
 * zdarzenia (harmonogram chodzi w `app.timezone`, czyli UTC). Sprawdzamy cały
 * rok, bo zmiana czasu przesuwa start w Polsce o godzinę.
 *
 * Paczka nie wychodzi naraz: list numer N dostaje opóźnienie
 * `N × odstep_sekund`, więc okno musi pomieścić także ostatni z
 * `dzienny_limit` listów. Kontrola dodatnia: ta sama reguła na zadaniu
 * o 03:30 i na zadaniu, którego paczka wjeżdża w 21:00.
 */
class HarmonogramDigestuSzanujeCiszyNocnejTest extends TestCase
{
    private const POCZATEK_DNIA = '08:00';

    private const KONIEC_DNIA = '21:00';

    public function test_wysylka_podsumowan_miesci_sie_miedzy_8_a_21_czasu_polskiego(): void
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => $e->description === 'kuking:wyslij-podsumowania');

        $this->assertNotNull($zadanie, 'Brak zadania `kuking:wyslij-podsumowania` w harmonogramie.');

        // Pięć pierwszych wystarczy do diagnozy; reszta roku zwykle powtarza to samo.
        $this->assertSame([], array_slice($this->naruszenia($zadanie, $this->czasPaczki()), 0, 5));
    }

    public function test_kontrola_dodatnia_nocny_start_i_paczka_po_21_sa_wykrywane(): void
    {
        $schedule = new Schedule('UTC');
        $nocne = $schedule->call(fn () => null)->name('kontrola-nocna')->dailyAt('03:30');
        $pozne = $schedule->call(fn () => null)->name('kontrola-pozna')->dailyAt('18:50');
        $poprawne = $schedule->call(fn () => null)->name('kontrola-poprawna')->dailyAt('08:30');

        $this->assertNotSame([], $this->naruszenia($nocne, 0), 'Reguła przepuściła start o 03:30.');
        // 18:50 UTC = 20:50 w Polsce latem; paczka 20 minut kończy się po 21:00.
        $this->assertNotSame([], $this->naruszenia($pozne, 20 * 60), 'Reguła przepuściła paczkę wjeżdżającą w ciszę nocną.');
        $this->assertSame([], $this->naruszenia($poprawne, 20 * 60), 'Reguła odrzuciła poprawny termin.');
    }

    /** Opóźnienie ostatniego listu w paczce, w sekundach. */
    private function czasPaczki(): int
    {
        $limit = max(0, (int) config('kuking.digest.dzienny_limit'));
        $odstep = max(0, (int) config('kuking.digest.odstep_sekund'));

        $this->assertGreaterThan(0, $limit, 'Dzienny limit podsumowań jest zerowy — test nic by nie sprawdził.');

        return $limit * $odstep;
    }

    /**
     * Terminy z całego roku, których start albo koniec paczki wypada
     * w ciszy nocnej czasu polskiego.
     *
     * @return list<string>
     */
    private function naruszenia(Event $event, int $sekundyPaczki): array
    {
        $strefaZadania = $event->timezone ?? config('app.timezone');
        $cron = new CronExpression($event->expression);
        $termin = Carbon::parse('2026-01-01 00:00:00', $strefaZadania);
        $koniecRoku = $termin->copy()->addYear();
        $naruszenia = [];

        while (true) {
            $termin = Carbon::instance($cron->getNextRunDate($termin, 0, false, $strefaZadania));
            if ($termin->gte($koniecRoku)) {
                break;
            }

            $start = $termin->copy()->setTimezone(Czas::strefa());
            $koniec = $start->copy()->addSeconds($sekundyPaczki);
            $okno = [
                $start->copy()->setTimeFromTimeString(self::POCZATEK_DNIA),
                $start->copy()->setTimeFromTimeString(self::KONIEC_DNIA),
            ];

            if ($start->lt($okno[0]) || $koniec->gt($okno[1])) {
                $naruszenia[] = sprintf(
                    '%s: start %s, ostatni list %s (%s) — poza oknem %s–%s.',
                    $event->description ?? $event->expression,
                    $start->format('Y-m-d H:i'),
                    $koniec->format('H:i'),
                    Czas::strefa(),
                    self::POCZATEK_DNIA,
                    self::KONIEC_DNIA,
                );
            }
        }

        return $naruszenia;
    }
}
