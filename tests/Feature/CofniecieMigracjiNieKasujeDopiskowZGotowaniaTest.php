<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Models\CookingNote;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `cooking_notes` nie kasuje po cichu czyjegoś prywatnego
 * dopisku z gotowania (#2587, AGENTS.md §6 i D-088). Odmowa jest WĄSKA: na
 * pustej tabeli i przy samych wygasłych wierszach cofnięcie przechodzi
 * (kontrola dodatnia), przy niewygasłych odmawia i mówi, co zrobić.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli i przy wygasłych), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeDopiskowZGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_02_220000_create_cooking_notes_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_niewygasly_dopisek(): void
    {
        $this->zapiszDopisek();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Liczba niewygasłych dopisków, które znikną: 1.');

        try {
            $this->migracja()->down();
        } finally {
            $this->assertTrue(Schema::hasTable('cooking_notes'));
            $this->assertSame(1, CookingNote::query()->count());
        }
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_notes'));
    }

    public function test_przechodzi_gdy_wszystkie_wiersze_wygasly(): void
    {
        $this->zapiszDopisek()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_notes'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->zapiszDopisek();
        putenv('KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_notes'));
    }

    private function zapiszDopisek(): CookingNote
    {
        $osoba = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);

        return app(RoboczyDopisek::class)->zapisz($osoba, $recipe, 'dolane 50 ml', 0);
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
