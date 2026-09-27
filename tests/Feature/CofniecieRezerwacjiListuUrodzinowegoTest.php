<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Rollback nie może zgubić dzisiejszej bariery przed drugim listem. */
final class CofniecieRezerwacjiListuUrodzinowegoTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_26_200000_add_birthday_email_queued_on_to_users.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-12 08:40:00', 'UTC'));
    }

    public function test_odmawia_gdy_dzisiejszy_list_jest_w_kolejce_bez_potwierdzenia(): void
    {
        $osoba = $this->user('urodziny-rollback');
        $osoba->forceFill(['birthday_email_queued_on' => Czas::dzisiajData()])->save();

        $odmowa = null;
        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA, '--realpath' => false]);
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Rollback zgubił dzisiejszą rezerwację bez potwierdzenia wysyłki.');
        $this->assertStringContainsString('1 kont', $odmowa->getMessage());
        $this->assertTrue(Schema::hasColumn('users', 'birthday_email_queued_on'));
        $this->assertSame(Czas::dzisiajData(), $osoba->fresh()->birthday_email_queued_on?->toDateString());
    }

    public function test_potwierdzony_list_nie_blokuje_rollbacku(): void
    {
        $osoba = $this->user('urodziny-potwierdzone');
        $osoba->forceFill([
            'birthday_email_queued_on' => Czas::dzisiajData(),
            'birthday_email_sent_on' => Czas::dzisiajData(),
        ])->save();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('users', 'birthday_email_queued_on'));
        Artisan::call('migrate', ['--path' => self::MIGRACJA, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('users', 'birthday_email_queued_on'));
    }
}
