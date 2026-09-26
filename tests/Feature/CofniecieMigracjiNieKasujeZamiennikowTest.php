<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji zamienników nie kasuje po cichu tekstu autorów
 * (D-284, wzorzec D-088 i `hero_picks`).
 *
 * `DROP COLUMN substitutes` to utrata tego, co ludzie wpisali, a po `down()`
 * prawie zawsze idzie kolejny `migrate` — kolumna wraca pusta i nie ma błędu
 * do zauważenia. Odmowa musi być WĄSKA: na pustej kolumnie i na świeżej bazie
 * cofnięcie przechodzi (dwie kontrole dodatnie niżej).
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `throw` z `down()` oblewa
 * `test_cofniecie_odmawia_gdy_autor_wpisal_zamiennik` komunikatem
 * „Cofnięcie migracji przeszło…”.
 */
final class CofniecieMigracjiNieKasujeZamiennikowTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_26_100000_add_substitutes_to_recipe_ingredients.php';

    public function test_cofniecie_odmawia_gdy_autor_wpisal_zamiennik(): void
    {
        $this->skladnik('margaryna');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->fail('Cofnięcie migracji przeszło, mimo że w bazie stoi zamiennik wpisany przez autora.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba składników z zamiennikiem: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ ZAMIAST TEGO', $e->getMessage());
        }

        // Odmowa, która zdążyła zdjąć kolumnę, byłaby tylko ładniejszym
        // komunikatem o tej samej utracie.
        $this->assertTrue(Schema::hasColumn('recipe_ingredients', 'substitutes'));
        $this->assertSame('margaryna', RecipeIngredient::query()->value('substitutes'));
    }

    public function test_cofniecie_przechodzi_gdy_zamiennikow_nie_ma(): void
    {
        // Są składniki, ale żaden nie ma zamiennika — nie ma czego stracić.
        $this->skladnik(null);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('recipe_ingredients', 'substitutes'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('recipe_ingredients', 'substitutes'));
    }

    public function test_cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('recipe_ingredients', 'substitutes'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('recipe_ingredients', 'substitutes'));
    }

    public function test_jawna_zgoda_ze_srodowiska_odblokowuje_cofniecie(): void
    {
        $this->skladnik('margaryna');

        putenv('KUKING_ROLLBACK_KASUJ_ZAMIENNIKI=true');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->assertFalse(Schema::hasColumn('recipe_ingredients', 'substitutes'), 'Jawna zgoda nie odblokowała cofnięcia.');
        } finally {
            putenv('KUKING_ROLLBACK_KASUJ_ZAMIENNIKI');
        }

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
    }

    private function skladnik(?string $zamiennik): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autorka_cofniecia')->getKey()]);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'ingredient_text' => '200 g masła',
            'substitutes' => $zamiennik,
            'position' => 0,
        ]);
    }
}
