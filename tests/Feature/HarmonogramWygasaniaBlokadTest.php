<?php

declare(strict_types=1);

namespace Tests\Feature;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regresja #1002: gołe `withoutOverlapping()` trzyma blokadę 1440 minut.
 * Po śmierci procesu bez sygnału (SIGKILL przy wdrożeniu) zadanie co pięć
 * minut milczało dobę, a dzienne gubiło dwa terminy. Każda blokada ma
 * wygasać PRZED następnym planowym terminem swojego zadania.
 *
 * Test czyta zdarzenia z `Schedule::events()`, nie tekst `routes/console.php`,
 * więc nie potrzebuje wpisu w `scripts/kontrole-negatywne-alfa08.py`.
 * Kontrolę dodatnią robi drugi test: ta sama reguła na gołym zdarzeniu.
 */
class HarmonogramWygasaniaBlokadTest extends TestCase
{
    public function test_kazda_blokada_harmonogramu_wygasa_przed_nastepnym_terminem(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');

        $zBlokada = 0;
        $bledy = [];
        foreach ($events as $event) {
            if (! $event->withoutOverlapping) {
                continue;
            }
            $zBlokada++;
            if (($blad = $this->bladWygasania($event)) !== null) {
                $bledy[] = $blad;
            }
        }

        $this->assertGreaterThan(0, $zBlokada, 'Żadne zadanie nie ma `withoutOverlapping()` — test nic nie sprawdził.');
        $this->assertSame([], $bledy, "Blokady harmonogramu wiszą za długo:\n".implode("\n", $bledy));
    }

    public function test_kontrola_dodatnia_gole_without_overlapping_jest_wykrywane(): void
    {
        $schedule = new Schedule('UTC');
        $gole = $schedule->call(fn () => null)->name('kontrola-gola')->everyFiveMinutes()->withoutOverlapping();
        $dzienne = $schedule->call(fn () => null)->name('kontrola-dzienna')->dailyAt('03:20')->withoutOverlapping();
        $poprawne = $schedule->call(fn () => null)->name('kontrola-poprawna')->everyFiveMinutes()->withoutOverlapping(4);

        $this->assertNotNull($this->bladWygasania($gole), 'Reguła przepuściła gołe withoutOverlapping() przy zadaniu co 5 minut.');
        $this->assertNotNull($this->bladWygasania($dzienne), 'Reguła przepuściła gołe withoutOverlapping() przy zadaniu dziennym.');
        $this->assertNull($this->bladWygasania($poprawne), 'Reguła odrzuciła poprawną blokadę.');
    }

    /** Najkrótszy odstęp między kolejnymi terminami w ciągu tygodnia, w minutach. */
    private function najkrotszyOdstep(Event $event): int
    {
        $cron = new CronExpression($event->expression);
        $start = Carbon::parse('2026-09-21 00:00:00', 'UTC');
        $poprzedni = Carbon::instance($cron->getNextRunDate($start, 0, true, 'UTC'));
        $najkrotszy = PHP_INT_MAX;
        // Tydzień z zapasem — wystarcza na co-minutowe, godzinowe i dzienne.
        while ($poprzedni->lt($start->copy()->addDays(8))) {
            $nastepny = Carbon::instance($cron->getNextRunDate($poprzedni, 0, false, 'UTC'));
            $najkrotszy = min($najkrotszy, (int) $poprzedni->diffInMinutes($nastepny, true));
            $poprzedni = $nastepny;
        }

        return $najkrotszy;
    }

    private function bladWygasania(Event $event): ?string
    {
        $odstep = $this->najkrotszyOdstep($event);
        $nazwa = $event->description ?? $event->expression;

        if ($event->expiresAt < 1) {
            return "{$nazwa}: blokada {$event->expiresAt} min — nie chroni przed nakładaniem.";
        }
        if ($event->expiresAt >= $odstep) {
            return "{$nazwa}: blokada {$event->expiresAt} min, a odstęp między terminami {$odstep} min.";
        }

        return null;
    }
}
