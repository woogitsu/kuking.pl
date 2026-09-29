<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Models\CookingProgress;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji etapu 2 synchronizacji gotowania (#2016) nie kasuje po
 * cichu zapamiętanych porcji ani składników „przygotowanych” (AGENTS.md §6,
 * D-088). Odmowa jest WĄSKA: sam wiersz z krokami (etap 1) i wiersze wygasłe
 * nie blokują cofnięcia (kontrola dodatnia).
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście bez nich), nie asertuje na treści źródła.
 */
class CofniecieMigracjiPorcjiISkladnikowGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_29_193700_add_servings_and_prepared_ingredients_to_cooking_progress.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_zapisane_porcje(): void
    {
        $this->zapiszPostep()->forceFill(['servings' => 6])->save();

        $this->assertOdmowa();
    }

    public function test_odmawia_gdy_ktos_ma_przygotowane_skladniki(): void
    {
        $this->zapiszPostep()->forceFill(['prepared_ingredient_ids' => ['a-b-c']])->save();

        $this->assertOdmowa();
    }

    public function test_przechodzi_gdy_wiersz_ma_tylko_kroki(): void
    {
        $this->zapiszPostep();

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('cooking_progress', 'servings'));
        $this->assertFalse(Schema::hasColumn('cooking_progress', 'prepared_ingredient_ids'));
        $this->assertSame(1, CookingProgress::query()->count(), 'Wiersz z krokami (etap 1) zostaje.');
    }

    public function test_przechodzi_gdy_wiersz_z_porcjami_wygasl(): void
    {
        $this->zapiszPostep()->forceFill(['servings' => 6, 'expires_at' => now()->subMinute()])->save();

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('cooking_progress', 'servings'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->zapiszPostep()->forceFill(['servings' => 6])->save();
        putenv('KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('cooking_progress', 'servings'));
    }

    public function test_up_po_down_przywraca_kolumny_z_ograniczeniami(): void
    {
        $this->migracja()->down();
        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('cooking_progress', 'servings'));
        $this->assertTrue(Schema::hasColumn('cooking_progress', 'prepared_ingredient_ids'));
    }

    private function assertOdmowa(): void
    {
        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby czyjeś porcje lub składniki.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA=1', $e->getMessage());
            $this->assertStringContainsString('Liczba niewygasłych postępów, którym zniknie ta część: 1.', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('cooking_progress', 'servings'));
    }

    private function zapiszPostep(): CookingProgress
    {
        $osoba = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);

        return app(PostepGotowania::class)->wlacz($osoba, $recipe, [], []);
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
