<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Retencja `failed_jobs`: 30 dni — decyzja właściciela z 25.09.2026
 * (`docs/DECISIONS.md`, sekcja „TOKEN W BAZIE LEŻY WYŁĄCZNIE JAKO SKRÓT”).
 *
 * Harmonogram czytamy z `Schedule::events()`, nie z tekstu
 * `routes/console.php`. Test zachowania uruchamia DOMKNIĘCIE zarejestrowanego
 * zdarzenia, a nie osobne `Artisan::call()` — sprawdza więc to, co naprawdę
 * pójdzie o 05:20, razem z parametrem `--hours`.
 */
class CzyszczenieNieudanychZadanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_harmonogram_ma_codzienne_czyszczenie_failed_jobs_po_720_godzinach(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');

        $zadanie = collect($events)->firstWhere('description', 'queue:prune-failed');
        $this->assertInstanceOf(CallbackEvent::class, $zadanie, 'W harmonogramie brak zadania queue:prune-failed.');

        $this->assertSame([], $this->bledyCzyszczenia($zadanie));
        $this->assertSame('20 5 * * *', $zadanie->expression, 'Zadanie ma chodzić o 05:20 — wolnym miejscu nocnego pasma.');
    }

    public function test_kontrola_dodatnia_regula_odrzuca_zle_zarejestrowane_zadanie(): void
    {
        $schedule = new Schedule('UTC');
        $tydzien = $schedule->call($this->domkniecie('queue:prune-failed', ['--hours' => 168]))
            ->dailyAt('05:20')->onOneServer()->withoutOverlapping(120);
        $bezBlokady = $schedule->call($this->domkniecie('queue:prune-failed', ['--hours' => 720]))
            ->dailyAt('05:20')->onOneServer();
        $coGodzine = $schedule->call($this->domkniecie('queue:prune-failed', ['--hours' => 720]))
            ->hourly()->onOneServer()->withoutOverlapping(50);
        $inna = $schedule->call($this->domkniecie('queue:flush', []))
            ->dailyAt('05:20')->onOneServer()->withoutOverlapping(120);
        $poprawne = $schedule->call($this->domkniecie('queue:prune-failed', ['--hours' => 720]))
            ->dailyAt('05:20')->onOneServer()->withoutOverlapping(120);

        $this->assertNotSame([], $this->bledyCzyszczenia($tydzien), 'Reguła przepuściła 168 godzin zamiast 720.');
        $this->assertNotSame([], $this->bledyCzyszczenia($bezBlokady), 'Reguła przepuściła zadanie bez withoutOverlapping.');
        $this->assertNotSame([], $this->bledyCzyszczenia($coGodzine), 'Reguła przepuściła zadanie nie-codzienne.');
        $this->assertNotSame([], $this->bledyCzyszczenia($inna), 'Reguła przepuściła inną komendę.');
        $this->assertSame([], $this->bledyCzyszczenia($poprawne), 'Reguła odrzuciła poprawnie zarejestrowane zadanie.');
    }

    public function test_zadanie_kasuje_wiersze_starsze_niz_30_dni_i_zostawia_mlodsze(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 05:20:00', 'UTC'));

        $stary = $this->nieudaneZadanie(Carbon::now()->subDays(31));
        $tuzPrzedGranica = $this->nieudaneZadanie(Carbon::now()->subHours(721));
        $mlody = $this->nieudaneZadanie(Carbon::now()->subDays(29));
        $swiezy = $this->nieudaneZadanie(Carbon::now()->subHour());

        // KONTROLA DODATNIA: przed przebiegiem leżą wszystkie cztery — bez tego
        // „stary zniknął" przechodziłoby także przy wstawieniu, które nie zadziałało.
        $this->assertSame(4, DB::table('failed_jobs')->count());

        $zadanie = collect(app(Schedule::class)->events())->firstWhere('description', 'queue:prune-failed');
        $this->assertInstanceOf(CallbackEvent::class, $zadanie);
        $kod = $this->app->call($this->callback($zadanie));

        $this->assertSame(0, $kod);
        $zostaly = DB::table('failed_jobs')->pluck('uuid')->all();
        $this->assertNotContains($stary, $zostaly, 'Wiersz starszy niż 30 dni nie zniknął.');
        $this->assertNotContains($tuzPrzedGranica, $zostaly, 'Wiersz sprzed 721 godzin nie zniknął.');
        $this->assertContains($mlody, $zostaly, 'Zniknął wiersz młodszy niż 30 dni.');
        $this->assertContains($swiezy, $zostaly, 'Zniknął świeży wiersz.');
        $this->assertCount(2, $zostaly);
    }

    /** @return list<string> */
    private function bledyCzyszczenia(Event $event): array
    {
        $bledy = [];
        if (! $event instanceof CallbackEvent) {
            return ['to nie jest CallbackEvent (Schedule::command() wymaga proc_open)'];
        }

        $zmienne = (new ReflectionFunction($this->callback($event)))->getStaticVariables();
        if (($zmienne['komenda'] ?? null) !== 'queue:prune-failed') {
            $bledy[] = 'komenda: '.var_export($zmienne['komenda'] ?? null, true);
        }
        if (($zmienne['parametry'] ?? null) !== ['--hours' => 720]) {
            $bledy[] = 'parametry: '.var_export($zmienne['parametry'] ?? null, true);
        }
        if (! preg_match('/^\d{1,2} \d{1,2} \* \* \*$/', $event->expression)) {
            $bledy[] = "nie codziennie: {$event->expression}";
        }
        if (! $event->withoutOverlapping || $event->expiresAt < 1) {
            $bledy[] = 'brak withoutOverlapping z jawnymi minutami';
        }
        if (! $event->onOneServer) {
            $bledy[] = 'brak onOneServer';
        }

        return $bledy;
    }

    private function callback(CallbackEvent $event): Closure
    {
        $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($event);
        $this->assertInstanceOf(Closure::class, $callback);

        return $callback;
    }

    /**
     * Ten sam kształt domknięcia co `Harmonogram::wykonaj()` — zmienne
     * `$komenda` i `$parametry` — ale bez uruchamiania czegokolwiek.
     *
     * @param  array<string, mixed>  $parametry
     */
    private function domkniecie(string $komenda, array $parametry): Closure
    {
        return function () use ($komenda, $parametry): int {
            return 0;
        };
    }

    private function nieudaneZadanie(Carbon $kiedy): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName":"Test"}',
            'exception' => 'RuntimeException: test',
            'failed_at' => $kiedy,
        ]);

        return $uuid;
    }
}
