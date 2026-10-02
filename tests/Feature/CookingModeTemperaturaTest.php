<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/** Przeliczenie °F ↔ °C pod krokiem trybu gotowania (#2585). */
class CookingModeTemperaturaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private function przepis(string ...$kroki): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('autortemp')->getKey()]);

        foreach (array_values($kroki) as $pozycja => $instrukcja) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $pozycja,
                'instruction' => $instrukcja,
            ]);
        }

        return $recipe;
    }

    public function test_krok_z_temperatura_ma_rozwijany_blok_a_tekst_kroku_zostaje(): void
    {
        $recipe = $this->przepis('Piecz w 350°F przez godzinę.');

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('<details class="cook-temperatura"', false)
            ->assertSee('Przelicz temperaturę')
            ->assertSee('350°F to około 175°C')
            ->assertSee('<p class="cook-step-tekst">Piecz w 350°F przez godzinę.</p>', false);
    }

    public function test_krok_bez_temperatury_nie_ma_bloku(): void
    {
        $recipe = $this->przepis('Piecz w 180 stopni przez 40 minut. Dodaj witaminę C.');

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('cook-temperatura', false)
            ->assertDontSee('Przelicz temperaturę');
    }

    public function test_blok_dotyczy_tylko_biezacego_kroku(): void
    {
        $recipe = $this->przepis('Piecz w 180°C.', 'Ostudź ciasto.');

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]))
            ->assertOk()
            ->assertSee('Ostudź ciasto.')
            ->assertDontSee('Przelicz temperaturę');
    }
}
