<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** Mierzymy prawdziwe zdarzenia; komendy i blokady są atrapami, bez skutków ubocznych. */
class HarmonogramSprawdzaKodWyjsciaTest extends TestCase
{
    public static function results(): array
    {
        return ['sukces' => [0], 'blad' => [1], 'bledne_argumenty' => [2], 'sygnal' => [137], 'wyjatek' => [null]];
    }

    #[DataProvider('results')]
    public function test_prawdziwa_komenda_przechodzi_przez_zarejestrowane_zdarzenie(?int $code): void
    {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'kuking:sprawdz-kolejke');
        $this->assertInstanceOf(CallbackEvent::class, $event);
        Artisan::command('kuking:sprawdz-kolejke', function () use ($code) {
            if ($code === null) {
                throw new RuntimeException('Kontrolowana awaria komendy.');
            }

            return $code;
        });
        $mutex = Mockery::mock(EventMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnTrue();
        $mutex->shouldReceive('forget')->once();
        $event->mutex = $mutex;
        $success = $failure = 0;
        $event->onSuccess(function () use (&$success) {
            $success++;
        });
        $event->onFailure(function () use (&$failure) {
            $failure++;
        });
        $exception = null;
        try {
            $event->run($this->app);
        } catch (Throwable $caught) {
            $exception = $caught;
        }
        $this->assertSame($code === 0 ? 0 : 1, $event->exitCode);
        $this->assertSame($code === 0 ? 1 : 0, $success);
        $this->assertSame($code === 0 ? 0 : 1, $failure);
        if ($code === null) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
        } else {
            $this->assertNull($exception);
        }
    }

    #[DataProvider('results')]
    public function test_kazde_zarejestrowane_zadanie_przekazuje_wynik_do_harmonogramu(?int $code): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(19, count($events), 'Skan nie czyta pełnego harmonogramu.');
        $names = array_column($events, 'description');
        foreach (['sprzataj-osierocone-zdjecia', 'kuking:sprawdz-kolejke', 'kuking:wyslij-podsumowania'] as $name) {
            $this->assertContains($name, $names);
        }

        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event);
            $this->assertTrue($event->withoutOverlapping, $event->description);
            $calls = 0;
            $kernel = Mockery::mock(Kernel::class);
            $kernel->shouldReceive('call')->once()->andReturnUsing(function () use ($code, &$calls) {
                $calls++;
                if ($code === null) {
                    throw new RuntimeException('Kontrolowana awaria komendy.');
                }

                return $code;
            });
            Artisan::swap($kernel);
            $mutex = Mockery::mock(EventMutex::class);
            $mutex->shouldReceive('create')->once()->with($event)->andReturnTrue();
            $mutex->shouldReceive('forget')->once()->with($event);
            $event->mutex = $mutex;
            $success = $failure = 0;
            $event->onSuccess(function () use (&$success) {
                $success++;
            });
            $event->onFailure(function () use (&$failure) {
                $failure++;
            });
            $exception = null;
            try {
                $event->run($this->app);
            } catch (Throwable $caught) {
                $exception = $caught;
            }
            $this->assertSame(1, $calls, 'Nie wykonano komendy: '.$event->description);
            $this->assertSame($code === 0 ? 0 : 1, $event->exitCode, 'Błędny status: '.$event->description);
            $this->assertSame($code === 0 ? 1 : 0, $success, $event->description);
            $this->assertSame($code === 0 ? 0 : 1, $failure, $event->description);
            if ($code === null) {
                $this->assertInstanceOf(RuntimeException::class, $exception);
                $this->assertSame('Kontrolowana awaria komendy.', $exception->getMessage());
            } else {
                $this->assertNull($exception);
            }
        }
    }

    public function test_zajeta_blokada_nie_uruchamia_komendy(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);
        Artisan::shouldReceive('call')->never();
        foreach ($events as $event) {
            $mutex = Mockery::mock(EventMutex::class);
            $mutex->shouldReceive('create')->once()->with($event)->andReturnFalse();
            $mutex->shouldNotReceive('forget');
            $event->mutex = $mutex;
            $event->run($this->app);
            $this->assertNull($event->exitCode);
        }
    }
}
