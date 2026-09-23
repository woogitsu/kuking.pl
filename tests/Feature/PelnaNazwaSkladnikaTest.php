<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PelnaNazwaSkladnikaTest extends TestCase
{
    use RefreshDatabase;

    public static function formularze(): array
    {
        return ['wiersze' => [false], 'jedno pole tekstowe' => [true]];
    }

    public static function graniceNazwy(): array
    {
        $przypadki = [];
        foreach ([160, 161, 240] as $dlugosc) {
            foreach ([false, true] as $jednoPole) {
                $przypadki[] = [$jednoPole, $dlugosc];
            }
        }

        return $przypadki;
    }

    #[DataProvider('graniceNazwy')]
    public function test_publikacja_i_edycja_przyjmuja_pelna_nazwe_bez_obcinania(bool $jednoPole, int $dlugosc): void
    {
        $this->actingAs($this->user('skladnik526'));
        $tekst = str_repeat('a', $dlugosc - 1).'z';
        $dane = $this->dane($tekst, $jednoPole);
        $this->post(route('recipes.store'), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $recipe = Recipe::sole();
        $this->assertSame($tekst, RecipeIngredient::sole()->ingredient_text);
        $this->assertSame($tekst, Ingredient::sole()->canonical_name);
        $this->assertSame($tekst, Ingredient::sole()->normalized_name);
        $this->assertSame(Ingredient::sole()->getKey(), RecipeIngredient::sole()->ingredient_id);

        // Ta sama długość i inny ostatni znak: odcięcie końcówki nie może
        // przypadkiem przejść jako zachowanie składnika ani scalić nazw.
        $poEdycji = str_repeat('a', $dlugosc - 1).'y';
        $dane = $this->dane($poEdycji, $jednoPole);
        $this->put(route('recipes.update', $recipe), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_ingredients', 1);
        $this->assertDatabaseCount('ingredients', 2);
        $this->assertSame($poEdycji, RecipeIngredient::sole()->ingredient_text);
        $this->assertSame($poEdycji, Ingredient::findOrFail(RecipeIngredient::sole()->ingredient_id)->canonical_name);
        $this->get(route('recipes.edit', $recipe))->assertOk()->assertSee($poEdycji);
    }

    #[DataProvider('formularze')]
    public function test_normalizacja_moze_byc_dluzsza_niz_240_a_rownawazne_nazwy_nadal_lacza_sie(bool $jednoPole): void
    {
        $this->actingAs($this->user('transliteracja526'));
        $tekst = str_repeat('Æ', 240);
        $this->assertSame(480, mb_strlen(Ingredient::normalize($tekst)));
        $dane = $this->dane($tekst, $jednoPole);
        $this->post(route('recipes.store'), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $recipe = Recipe::sole();
        $id = Ingredient::sole()->getKey();
        $this->assertSame($tekst, Ingredient::sole()->canonical_name);
        $this->assertSame(str_repeat('ae', 240), Ingredient::sole()->normalized_name);
        $this->assertSame($tekst, RecipeIngredient::sole()->ingredient_text);
        $poEdycji = str_repeat('æ', 240);
        $dane = $this->dane($poEdycji, $jednoPole);
        $this->put(route('recipes.update', $recipe), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ingredients', 1);
        $this->assertDatabaseCount('recipe_ingredients', 1);
        $this->assertSame($id, RecipeIngredient::sole()->ingredient_id);
        $this->assertSame($poEdycji, RecipeIngredient::sole()->ingredient_text);
    }

    private function dane(string $tekst, bool $jednoPole): array
    {
        return ['title' => 'Zupa', 'visibility' => 'public'] + ($jednoPole
            ? ['skladniki_tekst' => $tekst, 'przygotowanie_tekst' => 'Gotuj przez kilka minut.']
            : ['ingredients' => [['text' => $tekst, 'no_amount' => '1']], 'steps' => [['instruction' => 'Gotuj przez kilka minut.']]]);
    }
}
