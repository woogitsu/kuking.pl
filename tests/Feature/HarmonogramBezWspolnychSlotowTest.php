<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Zadania codzienne nie dzielą slotu (#1717).
 *
 * `routes/console.php` rozsuwa nocne zadania co dziesięć minut, bo w roli
 * `all` harmonogram chodzi w jednym procesie razem z serwerem. Ta reguła
 * żyła tylko w komentarzu — dwie równoległe gałęzie wstawiły więc różne
 * zadania na ten sam slot 05:20 (`queue:prune-failed` z main
 * i `kuking:sprzataj-usuniete-tresci` z #1717) i nic tego nie złapało.
 *
 * Sprawdzamy wyłącznie zadania codzienne o stałej godzinie (`m h * * *`):
 * zadania co godzinę (`hourly()`, `hourlyAt()`) i częstsze świadomie
 * przecinają się z nimi raz na dobę — opisuje to komentarz przy pierwszym
 * zadaniu godzinowym w `routes/console.php`.
 *
 * Test czyta zdarzenia z `Schedule::events()`, nie tekst `routes/console.php`,
 * więc nie potrzebuje wpisu w `scripts/kontrole-negatywne-alfa08.py`.
 * Kontrolę dodatnią robi drugi test: ta sama reguła na sztucznym harmonogramie.
 */
class HarmonogramBezWspolnychSlotowTest extends TestCase
{
    /** Najmniejszy dopuszczalny odstęp między dwoma zadaniami codziennymi. */
    private const ODSTEP_MINUT = 10;

    public function test_zadania_codzienne_stoja_co_najmniej_dziesiec_minut_od_siebie(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');

        $codzienne = $this->codzienne($events);
        $this->assertGreaterThanOrEqual(10, count($codzienne), 'Za mało zadań codziennych — test nic nie sprawdził.');

        $this->assertSame([], $this->kolizje($codzienne), "Zadania codzienne za blisko siebie:\n".implode("\n", $this->kolizje($codzienne)));
    }

    public function test_kontrola_dodatnia_wspolny_slot_i_zbyt_maly_odstep_sa_wykrywane(): void
    {
        $schedule = new Schedule;
        $schedule->call(fn () => null)->name('pierwsze')->dailyAt('05:20');
        $schedule->call(fn () => null)->name('ten-sam-slot')->dailyAt('05:20');
        $schedule->call(fn () => null)->name('za-blisko')->dailyAt('05:25');
        $schedule->call(fn () => null)->name('w-porzadku')->dailyAt('05:40');
        $schedule->call(fn () => null)->name('godzinowe-nie-liczy-sie')->hourlyAt(20);

        $kolizje = $this->kolizje($this->codzienne($schedule->events()));

        $this->assertCount(2, $kolizje, implode("\n", $kolizje));
        $this->assertStringContainsString('ten-sam-slot', implode("\n", $kolizje));
        $this->assertStringContainsString('za-blisko', implode("\n", $kolizje));
        $this->assertStringNotContainsString('w-porzadku', implode("\n", $kolizje));
    }

    /**
     * @param  array<Event>  $events
     * @return list<array{nazwa: string, minuta: int}> minuta doby, rosnąco
     */
    private function codzienne(array $events): array
    {
        $strefy = [];
        $wynik = [];

        foreach ($events as $event) {
            if (preg_match('/^(\d+) (\d+) \* \* \*$/', $event->expression, $m) !== 1) {
                continue;
            }

            $strefy[(string) ($event->timezone ?? 'domyślna')] = true;
            $wynik[] = [
                'nazwa' => $event->description ?? $event->expression,
                'minuta' => (int) $m[2] * 60 + (int) $m[1],
            ];
        }

        // Porównanie godzin ma sens tylko w jednej strefie czasowej.
        $this->assertLessThanOrEqual(1, count($strefy), 'Zadania codzienne w różnych strefach: '.implode(', ', array_keys($strefy)));

        usort($wynik, fn (array $a, array $b): int => $a['minuta'] <=> $b['minuta']);

        return $wynik;
    }

    /**
     * Każda para sąsiadów bliżej niż `ODSTEP_MINUT`, także przez północ.
     *
     * @param  list<array{nazwa: string, minuta: int}>  $codzienne
     * @return list<string>
     */
    private function kolizje(array $codzienne): array
    {
        $bledy = [];
        $ile = count($codzienne);

        // Kolejne pary po godzinie, a przy co najmniej trzech zadaniach
        // także ostatnie z pierwszym przez północ (przy dwóch to ta sama para).
        $pary = [];
        for ($i = 0; $i < $ile - 1; $i++) {
            $pary[] = [$codzienne[$i], $codzienne[$i + 1]];
        }
        if ($ile > 2) {
            $pary[] = [$codzienne[$ile - 1], $codzienne[0]];
        }

        foreach ($pary as [$teraz, $nastepne]) {
            $odstep = ($nastepne['minuta'] - $teraz['minuta'] + 1440) % 1440;

            if ($odstep < self::ODSTEP_MINUT) {
                $bledy[] = sprintf(
                    '%s (%s) i %s (%s): %d min',
                    $teraz['nazwa'], $this->godzina($teraz['minuta']),
                    $nastepne['nazwa'], $this->godzina($nastepne['minuta']),
                    $odstep,
                );
            }
        }

        return $bledy;
    }

    private function godzina(int $minuta): string
    {
        return sprintf('%02d:%02d', intdiv($minuta, 60), $minuta % 60);
    }
}
