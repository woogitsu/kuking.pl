<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\PushDailyBudget;
use App\Domain\Notifications\PushDeliveryWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PushDeliveryLimitsTest extends TestCase
{
    use RefreshDatabase;

    public static function hours(): array
    {
        return [
            'lato przed cisza' => ['2026-07-01 18:59:59', 'Europe/Warsaw', '2026-07-01 18:59:59'],
            'lato poczatek ciszy' => ['2026-07-01 19:00:00', 'Europe/Warsaw', '2026-07-02 06:00:00'],
            'zima poczatek ciszy' => ['2026-01-01 20:00:00', 'Europe/Warsaw', '2026-01-02 07:00:00'],
            'przed osma' => ['2026-07-02 05:59:59', 'Europe/Warsaw', '2026-07-02 06:00:00'],
            'dokladnie osma' => ['2026-07-02 06:00:00', 'Europe/Warsaw', '2026-07-02 06:00:00'],
            'zmiana na letni' => ['2026-03-28 20:00:00', 'Europe/Warsaw', '2026-03-29 06:00:00'],
            'zmiana na zimowy' => ['2026-10-24 19:00:00', 'Europe/Warsaw', '2026-10-25 07:00:00'],
            'nowy jork noc' => ['2026-07-02 01:00:00', 'America/New_York', '2026-07-02 12:00:00'],
            'tokio dzien' => ['2026-07-01 23:00:00', 'Asia/Tokyo', '2026-07-01 23:00:00'],
        ];
    }

    #[DataProvider('hours')]
    public function test_cisza_w_strefie_odbiorcy_wyznacza_moment_w_utc(string $now, string $timezone, string $expected): void
    {
        $instant = CarbonImmutable::parse($now, 'UTC');
        $actual = (new PushDeliveryWindow)->nextAllowedAt($instant, $timezone);
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $actual->timezoneName);
        $this->assertSame($now, $instant->format('Y-m-d H:i:s'));
    }

    public function test_brak_lub_blad_strefy_nie_zamienia_sie_po_cichu_w_warszawe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PushDeliveryWindow)->nextAllowedAt(CarbonImmutable::now('UTC'), '');
    }

    public function test_noc_nie_zuzywa_limitu_i_nie_kasuje_powiadomienia(): void
    {
        $user = $this->user();
        $notification = $user->notifications()->create(['type' => 'cooked_event.created', 'data' => []]);
        $this->travelTo(CarbonImmutable::parse('2026-07-01 19:00:00', 'UTC'));
        $budget = app(PushDailyBudget::class);
        $this->assertFalse($budget->reserve($user, 'Europe/Warsaw'));
        $this->assertDatabaseCount('push_daily_reservations', 0);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-07-02 06:00:00', 'UTC'));
        $this->assertTrue($budget->reserve($user, 'Europe/Warsaw'));
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => null]);
    }

    public function test_jedna_rezerwacja_na_lokalna_dobe_osobno_dla_kazdej_osoby(): void
    {
        $user = $this->user();
        $other = $this->user();
        $budget = app(PushDailyBudget::class);
        $this->travelTo(CarbonImmutable::parse('2026-07-01 23:00:00', 'UTC'));
        $this->assertTrue($budget->reserve($user, 'Asia/Tokyo'));
        $this->assertFalse($budget->reserve($user, 'Asia/Tokyo'));
        $this->assertTrue($budget->reserve($other, 'Asia/Tokyo'));
        $this->assertDatabaseHas('push_daily_reservations', ['user_id' => $user->id, 'local_date' => '2026-07-02']);
        $this->travelTo(CarbonImmutable::parse('2026-07-02 00:01:00', 'UTC'));
        $this->assertFalse($budget->reserve($user, 'Asia/Tokyo'), 'Północ UTC nie odnawia lokalnego limitu.');
        $this->travelTo(CarbonImmutable::parse('2026-07-02 23:00:00', 'UTC'));
        $this->assertTrue($budget->reserve($user, 'Asia/Tokyo'));
        $this->assertDatabaseCount('push_daily_reservations', 3);
    }

    public function test_zmiana_strefy_nie_daje_drugiej_rezerwacji_w_tej_samej_lokalnej_dobie(): void
    {
        $user = $this->user();
        $budget = app(PushDailyBudget::class);
        $this->travelTo(CarbonImmutable::parse('2026-07-01 23:00:00', 'UTC'));
        $this->assertTrue($budget->reserve($user, 'Asia/Tokyo'));
        // W Los Angeles nadal 1 lipca, lecz pierwszy push był DZIŚ także tam.
        $this->assertFalse($budget->reserve($user, 'America/Los_Angeles'));
    }

    public function test_zamkniecie_konta_jest_czytane_ponownie(): void
    {
        $user = $this->user();
        $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC'));
        DB::table('users')->where('id', $user->id)->update(['status' => 'banned']);
        $this->assertFalse(app(PushDailyBudget::class)->reserve($user, 'Europe/Warsaw'));
        $this->assertDatabaseCount('push_daily_reservations', 0);
    }

    public function test_usuniete_konto_nie_dostaje_rezerwacji(): void
    {
        $user = $this->user();
        $user->delete();
        $this->assertFalse(app(PushDailyBudget::class)->reserve($user, 'Europe/Warsaw'));
    }

    public function test_czekanie_na_blokade_nie_omija_poczatku_ciszy(): void
    {
        $user = $this->user();
        $this->travelTo(CarbonImmutable::parse('2026-07-01 18:59:59', 'UTC'));
        $observed = false;
        DB::listen(function ($query) use (&$observed): void {
            if (! $observed && str_contains($query->sql, 'for update')) {
                $observed = true;
                $this->travelTo(CarbonImmutable::parse('2026-07-01 19:00:00', 'UTC'));
            }
        });
        $this->assertFalse(app(PushDailyBudget::class)->reserve($user, 'Europe/Warsaw'));
        $this->assertTrue($observed, 'Kontrola musi przejść przez rzeczywistą blokadę konta.');
        $this->assertDatabaseCount('push_daily_reservations', 0);
    }

    public function test_baza_sama_odrzuca_druga_rezerwacje_tego_samego_dnia(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC'));
        $user = $this->user();
        $this->assertTrue(app(PushDailyBudget::class)->reserve($user, 'Europe/Warsaw'));
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('push_daily_reservations')->insert([
            'user_id' => $user->id, 'local_date' => '2026-07-02', 'reserved_at' => now(),
        ]);
    }

    public function test_rollback_pustej_tabeli_przechodzi_ale_nie_kasuje_pamieci_limitu(): void
    {
        $migration = require database_path('migrations/2026_09_20_220000_create_push_daily_reservations.php');
        $migration->down();
        $migration->up();
        $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC'));
        $this->assertTrue(app(PushDailyBudget::class)->reserve($this->user(), 'Europe/Warsaw'));
        try {
            $migration->down();
            $this->fail('Wycofanie nie może odnawiać wykorzystanego limitu.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Zachowaj', $e->getMessage());
            $this->assertDatabaseCount('push_daily_reservations', 1);
        }
    }
}
