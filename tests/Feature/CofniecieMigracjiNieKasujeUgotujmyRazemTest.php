<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\WeeklyRecipePick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `weekly_recipe_picks` nie kasuje po cichu archiwum
 * „Ugotujmy razem” (F3, AGENTS.md §6 i D-088). Odmowa jest WĄSKA: na pustej
 * tabeli cofnięcie przechodzi (kontrola dodatnia), z danymi — odmawia i mówi,
 * co zrobić; zmienna środowiskowa pozwala świadomie przejść dalej.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeUgotujmyRazemTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_30_180000_create_weekly_recipe_picks_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM');
        parent::tearDown();
    }

    private function wybor(): void
    {
        $pick = new WeeklyRecipePick;
        $pick->forceFill(['week_starts_on' => '2026-09-28', 'recipe_id' => Recipe::factory()->create()->getKey()])->save();
    }

    public function test_odmawia_gdy_jest_archiwum_tygodni(): void
    {
        $this->wybor();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby archiwum „Ugotujmy razem”.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba tygodni, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('weekly_recipe_picks'));
        $this->assertSame(1, DB::table('weekly_recipe_picks')->count());
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('weekly_recipe_picks'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->wybor();
        putenv('KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('weekly_recipe_picks'));
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
