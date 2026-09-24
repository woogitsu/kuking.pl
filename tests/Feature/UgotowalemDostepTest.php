<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UgotowalemDostepTest extends TestCase
{
    use RefreshDatabase;

    public static function screens(): array
    {
        return [['recipes.show'], ['cooking.show']];
    }

    #[DataProvider('screens')]
    public function test_zaproszenie_respektuje_policy_dla_kazdego_stanu(string $screen): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Wymieszaj.']);
        $url = route('cooked.create', $recipe->slug);
        $this->get(route($screen, $recipe->slug))->assertOk()->assertDontSee($url, false);
        $reader = $this->user();
        $this->actingAs($reader)->get(route($screen, $recipe->slug))->assertOk()->assertSee($url, false);
        $reader->suspend(now()->addDay());
        $this->get($url)->assertForbidden();
        $this->get(route($screen, $recipe->slug))->assertOk()->assertDontSee($url, false);
    }
}
