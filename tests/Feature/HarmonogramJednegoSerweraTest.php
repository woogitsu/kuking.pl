<?php

declare(strict_types=1);

namespace Tests\Feature;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HarmonogramJednegoSerweraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseLock łapie konflikt UNIQUE. Osobne połączenie bez transakcji
        // testu odtwarza produkcję; konflikt nie zatruwa transakcji RefreshDatabase.
        config([
            'database.connections.scheduler_test' => config('database.connections.pgsql'),
            'cache.stores.database.lock_connection' => 'scheduler_test',
            'cache.prefix' => 'scheduler-test-'.Str::uuid().'-',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('scheduler_test')->table('cache_locks')
                ->where('key', 'like', config('cache.prefix').'%')->delete();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Dwa świeże schedulery po kolei, po zakończeniu pierwszego zadania.
     * To regresja duplikatu w tej samej minucie, nie test wyścigu procesów.
     * Komendy domenowe zastępujemy licznikiem: test nie wysyła listów.
     */
    public function test_drugi_scheduler_nie_powtarza_zakonczonych_zadan_w_tej_samej_minucie(): void
    {
        config(['cache.default' => 'database']);
        $this->app->make('cache')->forgetDriver();
        $calls = [];
        Artisan::partialMock()->shouldReceive('call')->andReturnUsing(function (string $command) use (&$calls): int {
            $calls[$command] = ($calls[$command] ?? 0) + 1;

            return 0;
        });

        // Każde rzeczywiste zadanie ma dojść do swojej minuty przynajmniej raz.
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');
        foreach ($events as $event) {
            $date = (new CronExpression($event->expression))
                ->getNextRunDate('2026-09-21 00:00:00', 0, true, 'UTC');
            $this->travelTo(Carbon::instance($date));
            $before = $calls;
            $this->runFreshScheduler();
            $afterFirst = $calls;
            $this->assertNotSame($before, $afterFirst, 'Kontrola dodatnia: zadanie nie wystartowało.');
            $this->runFreshScheduler();
            $this->assertSame($afterFirst, $calls, 'Drugi scheduler powtórzył zakończone zadanie: '.$event->description);
            // Następny testowany termin zaczyna od własnego cache blokad.
            DB::connection('scheduler_test')->table('cache_locks')
                ->where('key', 'like', config('cache.prefix').'%')->delete();
        }
        $this->assertCount(count($events), $calls, 'Nie wykonano wszystkich komend harmonogramu.');
    }

    /**
     * Strażnik pliku, nie zachowania: nowe zadanie dopisane do
     * `routes/console.php` bez `->onOneServer()` ma zapalić się natychmiast,
     * a nie dopiero przy następnym wdrożeniu na produkcji.
     */
    public function test_kazde_zadanie_harmonogramu_ma_ononeserver(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');

        $bez = [];
        foreach ($events as $event) {
            if ($event->onOneServer !== true) {
                $bez[] = $event->description ?? $event->expression;
            }
        }

        $this->assertSame([], $bez, 'Zadania harmonogramu bez `->onOneServer()`: '.implode(', ', $bez));
    }

    public function test_blokada_nie_zabiera_nastepnego_planowego_terminu(): void
    {
        config(['cache.default' => 'database']);
        $this->app->make('cache')->forgetDriver();
        // Nazwy, a nie sama liczba wywołań: co 5 minut chodzi więcej niż
        // jedno zadanie (od #599 także `kuking:puls-harmonogramu`), więc
        // „1, potem 2" przestało znaczyć „to samo zadanie dwa razy".
        $calls = [];
        Artisan::partialMock()->shouldReceive('call')->andReturnUsing(function (string $komenda) use (&$calls): int {
            $calls[] = $komenda;

            return 0;
        });
        $this->travelTo(Carbon::parse('2026-09-21 12:05:00', 'UTC'));
        $this->runFreshScheduler();
        $pierwszyTermin = $calls;
        $this->assertNotSame([], $pierwszyTermin, 'Kontrola dodatnia: o 12:05 nie wystartowało nic.');
        $calls = [];
        $this->travelTo(Carbon::parse('2026-09-21 12:10:00', 'UTC'));
        $this->runFreshScheduler();
        foreach ($pierwszyTermin as $komenda) {
            $this->assertContains($komenda, $calls, 'Blokada z 12:05 zabrała termin 12:10 zadaniu: '.$komenda);
        }
    }

    private function runFreshScheduler(): void
    {
        // Osobny obiekt usuwa lokalną pamięć mutexCache poprzedniego przebiegu.
        ScheduleFacade::swap(new Schedule('UTC'));
        require base_path('routes/console.php');
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);
        $this->assertSame(0, $command->run(new ArrayInput([]), new BufferedOutput));
    }
}
