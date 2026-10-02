<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Porcje\ZapamietanePorcje;
use App\Models\Recipe;
use App\Models\RecipeServingPreference;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `recipe_serving_preferences` nie kasuje po cichu
 * czyichś zapamiętanych liczb porcji (#2602, AGENTS.md §6 i D-088). Odmowa
 * jest WĄSKA: na pustej tabeli cofnięcie przechodzi (kontrola dodatnia), przy
 * zapisanym wyborze odmawia i mówi, co zrobić; zmienna środowiskowa pozwala
 * świadomie przejść dalej.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeZapamietanychPorcjiTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_02_210000_create_recipe_serving_preferences_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_zapamietana_liczbe(): void
    {
        $this->zapiszWybor();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby czyjś zapisany wybór.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba zapisanych wyborów, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('recipe_serving_preferences'));
        $this->assertSame(1, RecipeServingPreference::query()->count());
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('recipe_serving_preferences'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->zapiszWybor();
        putenv('KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('recipe_serving_preferences'));
    }

    public function test_baza_odrzuca_liczbe_spoza_zakresu(): void
    {
        $this->expectException(QueryException::class);

        $osoba = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey(), 'servings' => 4]);

        RecipeServingPreference::query()->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $osoba->getKey(),
            'recipe_id' => $recipe->getKey(),
            'servings' => 101,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function zapiszWybor(): void
    {
        $osoba = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey(), 'servings' => 4]);

        app(ZapamietanePorcje::class)->zapamietaj($osoba, $recipe, '2');
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
