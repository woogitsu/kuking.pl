<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback migracji urodzin nie kasuje po cichu dat podanych przez ludzi
 * (issue #1755, D-088). Odmowa przy danych i dwie kontrole dodatnie:
 * rollback przy pustych polach i na świeżej bazie MUSI przechodzić.
 */
class CofniecieMigracjiUrodzinTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_25_200000_add_birthday_to_users.php';

    public function test_cofniecie_odmawia_gdy_ktos_podal_date(): void
    {
        // Prawdziwa droga: formularz ustawień, nie ręczny UPDATE.
        $basia = $this->user('basia');
        $this->actingAs($basia)
            ->put(route('settings.birthday.update'), ['birthday_day' => '12', 'birthday_month' => '3'])
            ->assertSessionHasNoErrors();

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->fail('Rollback przeszedł, choć w bazie jest data urodzin, której `up()` nie odtworzy.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba kont z wpisaną datą urodzin (birthday_day, birthday_month): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        // Odmowa przed zdjęciem kolumn — dane zostały.
        $this->assertSame(12, $basia->fresh()->birthday_day);
        $this->assertSame(2, $this->ileKolumn());
    }

    public function test_cofniecie_przechodzi_gdy_nikt_nie_podal_daty(): void
    {
        $this->user('zofia');

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(0, $this->ileKolumn(), 'Rollback nie przeszedł, choć nikt nie podał daty.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(2, $this->ileKolumn());
    }

    public function test_cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(0, $this->ileKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(2, $this->ileKolumn());
    }

    private function ileKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name IN ('birthday_day', 'birthday_month')",
        ));
    }
}
