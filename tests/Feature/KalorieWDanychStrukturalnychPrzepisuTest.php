<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Recipe.nutrition.calories` w JSON-LD przepisu (#1996).
 *
 * Google wymaga zgodności danych strukturalnych z widoczną treścią, więc
 * kalorie trafiają do JSON-LD wtedy i tylko wtedy, gdy ta sama liczba stoi
 * w widocznej sekcji „Szacunkowe wartości odżywcze (na porcję)”.
 */
final class KalorieWDanychStrukturalnychPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ImportujWartosciOdzywcze::class)->handle();
    }

    #[Test]
    public function test_wiarygodny_wynik_daje_nutrition_zgodne_z_widoczna_liczba(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);

        $html = $this->strona($recipe);
        $ld = $this->recipeJsonLd($html);

        $this->assertSame(['@type' => 'NutritionInformation', 'calories' => '480 kcal'], $ld['nutrition']);
        // Ta sama liczba, to samo zaokrąglenie — widoczna na stronie.
        $this->assertStringContainsString('Szacunkowe wartości odżywcze (na porcję)', $html);
        $this->assertStringContainsString('ok. 480 kcal', $html);
        // Kalorie są „na porcję”, a recipeYield zostaje liczbą porcji.
        $this->assertSame($recipe->servingsLabel(), $ld['recipeYield']);
    }

    #[Test]
    public function test_wartosc_z_json_ld_jest_ta_sama_co_widoczna_dla_kilku_przepisow(): void
    {
        foreach ([[['150 g mąki pszennej', '1 jajko', '100 ml mleka'], 3], [['300 g mąki pszennej', '3 jajka'], 4]] as [$skladniki, $porcje]) {
            $html = $this->strona($this->przepis($skladniki, $porcje));
            $ld = $this->recipeJsonLd($html);

            $this->assertArrayHasKey('nutrition', $ld);
            preg_match('/ok\. (\d+) kcal/', $html, $m);
            $this->assertSame($m[1].' kcal', $ld['nutrition']['calories']);
        }
    }

    #[Test]
    public function test_ukryta_sekcja_nie_daje_nutrition_ani_kalorii_na_stronie(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);
        $recipe->forceFill(['pokazuj_wartosci_odzywcze' => false])->save();

        $html = $this->strona($recipe);

        $this->assertArrayNotHasKey('nutrition', $this->recipeJsonLd($html));
        $this->assertStringNotContainsString('kcal', $html);
    }

    #[Test]
    public function test_niepelne_pokrycie_skladnikow_nie_daje_nutrition(): void
    {
        $recipe = $this->przepis(['4 ziemniaki', '1 jajko', 'olej do smażenia'], 3);

        $html = $this->strona($recipe);

        $this->assertArrayNotHasKey('nutrition', $this->recipeJsonLd($html));
        $this->assertStringContainsString('Nie liczymy wartości odżywczych tego przepisu.', $html);
        $this->assertDoesNotMatchRegularExpression('/ok\\. \\d+ kcal/', $html);
    }

    #[Test]
    public function test_brak_liczby_porcji_nie_publikuje_calkowitej_wartosci_jako_porcji(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);
        $recipe->forceFill(['servings' => null])->save();

        $html = $this->strona($recipe);

        $this->assertStringContainsString('na cały przepis', $html);
        $this->assertArrayNotHasKey('nutrition', $this->recipeJsonLd($html));
    }

    #[Test]
    public function test_przepis_bez_skladnikow_nie_ma_nutrition(): void
    {
        $recipe = $this->przepis([], 2);

        $this->assertArrayNotHasKey('nutrition', $this->recipeJsonLd($this->strona($recipe)));
    }

    #[Test]
    public function test_kalkulator_liczy_raz_na_zadanie_mimo_json_ld_i_sekcji(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->strona($recipe);
        $slownik = collect(DB::getQueryLog())->filter(fn (array $z) => str_contains($z['query'], 'aliasy_skladnikow'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $slownik, 'JSON-LD i sekcja „wartości odżywcze” mają dzielić jedno przeliczenie.');
    }

    /** @param  list<string>  $skladniki */
    private function przepis(array $skladniki, int $porcje): Recipe
    {
        $recipe = Recipe::factory()->zeZdjeciem()->create([
            'servings' => $porcje,
            'author_id' => User::factory(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        foreach ($skladniki as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $recipe;
    }

    private function strona(Recipe $recipe): string
    {
        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();
        $this->assertIsString($html);

        return $html;
    }

    /** @return array<string, mixed> */
    private function recipeJsonLd(string $html): array
    {
        preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);
        foreach ($m[1] as $json) {
            $blok = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($blok['@type'] ?? null) === 'Recipe') {
                return $blok;
            }
        }
        $this->fail('Na stronie nie ma bloku JSON-LD typu Recipe (przepis musi być publiczny i mieć gotowe zdjęcie).');
    }
}
