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
 * Cofnięcie migracji `cooking_progress` nie kasuje po cichu czyjegoś
 * zapamiętanego postępu gotowania (#2016, AGENTS.md §6 i D-088). Odmowa jest
 * WĄSKA: na pustej tabeli i przy samych wygasłych wierszach cofnięcie
 * przechodzi (kontrola dodatnia), przy niewygasłych — odmawia i mówi, co
 * zrobić; zmienna środowiskowa pozwala świadomie przejść dalej.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli i przy wygasłych), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujePostepuGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_29_170000_create_cooking_progress_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_niewygasly_postep(): void
    {
        $this->zapiszPostep();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby czyjś postęp.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba niewygasłych postępów, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('cooking_progress'));
        $this->assertSame(1, CookingProgress::query()->count());
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_progress'));
    }

    public function test_przechodzi_gdy_wszystkie_wiersze_wygasly(): void
    {
        $this->zapiszPostep()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_progress'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->zapiszPostep();
        putenv('KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_progress'));
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
