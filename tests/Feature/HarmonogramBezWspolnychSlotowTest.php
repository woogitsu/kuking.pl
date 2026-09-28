<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
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

    public function test_kontrola_dodatnia_wykrywa_kolizje_miedzy_strefami_latem_i_zima(): void
    {
        $schedule = new Schedule;
        $schedule->call(fn () => null)->name('polski-czas')->dailyAt('17:47')->timezone('Europe/Warsaw');
        $schedule->call(fn () => null)->name('kolizja-latem')->dailyAt('15:50')->timezone('UTC');
        $schedule->call(fn () => null)->name('kolizja-zima')->dailyAt('16:50')->timezone('UTC');

        $kolizje = $this->kolizje($this->codzienne($schedule->events()));

        $this->assertCount(2, $kolizje, implode("\n", $kolizje));
        $this->assertStringContainsString('kolizja-latem', implode("\n", $kolizje));
        $this->assertStringContainsString('kolizja-zima', implode("\n", $kolizje));
    }

    /**
     * @param  array<Event>  $events
     * @return list<array{nazwa: string, minuta: int, strefa: string}>
     */
    private function codzienne(array $events): array
    {
        $wynik = [];

        foreach ($events as $event) {
            if (preg_match('/^(\d+) (\d+) \* \* \*$/', $event->expression, $m) !== 1) {
                continue;
            }

            $wynik[] = [
                'nazwa' => $event->description ?? $event->expression,
                'minuta' => (int) $m[2] * 60 + (int) $m[1],
                'strefa' => (string) ($event->timezone ?? config('app.timezone')),
            ];
        }

        return $wynik;
    }

    /**
     * Każda para sąsiadów bliżej niż `ODSTEP_MINUT`, także przez północ.
     *
     * @param  list<array{nazwa: string, minuta: int, strefa: string}>  $codzienne
     * @return list<string>
     */
    private function kolizje(array $codzienne): array
    {
        $bledy = [];

        // Ten sam lokalny slot ma inną godzinę UTC latem i zimą. Obie pory
        // sprawdzamy w jednej osi czasu; powtarzające się kolizje liczą się raz.
        foreach (['2026-01-15', '2026-07-15'] as $dzien) {
            $wUtc = array_map(function (array $event) use ($dzien): array {
                $czas = CarbonImmutable::parse($dzien, $event['strefa'])
                    ->startOfDay()->addMinutes($event['minuta'])->setTimezone('UTC');

                return [
                    'nazwa' => $event['nazwa'],
                    'minuta' => (int) $czas->format('H') * 60 + (int) $czas->format('i'),
                ];
            }, $codzienne);
            usort($wUtc, fn (array $a, array $b): int => $a['minuta'] <=> $b['minuta']);

            $ile = count($wUtc);
            $pary = [];
            for ($i = 0; $i < $ile - 1; $i++) {
                $pary[] = [$wUtc[$i], $wUtc[$i + 1]];
            }
            if ($ile > 2) {
                $pary[] = [$wUtc[$ile - 1], $wUtc[0]];
            }

            foreach ($pary as [$teraz, $nastepne]) {
                $odstep = ($nastepne['minuta'] - $teraz['minuta'] + 1440) % 1440;

                if ($odstep < self::ODSTEP_MINUT) {
                    $opis = sprintf(
                        '%s (%s UTC) i %s (%s UTC): %d min',
                        $teraz['nazwa'], $this->godzina($teraz['minuta']),
                        $nastepne['nazwa'], $this->godzina($nastepne['minuta']),
                        $odstep,
                    );
                    $bledy[$opis] = true;
                }
            }
        }

        return array_keys($bledy);
    }

    private function godzina(int $minuta): string
    {
        return sprintf('%02d:%02d', intdiv($minuta, 60), $minuta % 60);
    }
}
