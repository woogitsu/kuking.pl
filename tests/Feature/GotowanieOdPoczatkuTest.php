<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GotowanieOdPoczatkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_swiadomy_reset_czysci_tylko_odhaczenia_tego_przepisu(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $step = RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Wymieszaj.']);
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $other = Recipe::factory()->create(['author_id' => $recipe->author_id]);
        $otherStep = RecipeStep::create(['recipe_id' => $other->id, 'position' => 0, 'instruction' => 'Zagotuj.']);
        $otherKey = 'gotowanie.'.$other->id.'.zrobione';
        $cooked = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $recipe->author_id]);
        $url = route('cooking.show', $recipe->slug);
        $this->get($url)->assertOk()->assertDontSee('Zacznij od początku');
        $this->withSession([$otherKey => [$otherStep->id], 'inna-wartosc' => 'zostaje']);
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1])->assertSessionHas($key, [$step->id]);
        $this->get($url)->assertOk()->assertSee('Zacznij od początku')->assertSee('Zostaw odhaczenia')->assertSessionHas($key, [$step->id]);
        $resetUrl = '/przepisy/'.$recipe->slug.'/gotuj/od-poczatku';
        $this->get($resetUrl)->assertStatus(405)->assertSessionHas($key, [$step->id]);
        $this->post($resetUrl)->assertRedirect($url)->assertSessionMissing($key)->assertSessionHas($otherKey, [$otherStep->id])->assertSessionHas('inna-wartosc', 'zostaje');
        $this->assertModelExists($cooked);
        $this->assertModelExists($step);
        $this->get(route('cooking.show', $other->slug))->assertOk()->assertSee('Zrobione ✓');
        $this->get($url)->assertOk()->assertDontSee('Zrobione ✓')->assertDontSee('Zacznij od początku');
    }

    public function test_reset_nie_omija_policy(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id, 'visibility' => 'private']);
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $this->withSession([$key => ['krok']])->post('/przepisy/'.$recipe->slug.'/gotuj/od-poczatku')->assertForbidden()->assertSessionHas($key, ['krok']);
    }

    public function test_reset_wymaga_csrf(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $this->app['env'] = 'production';
        $this->withSession([$key => ['krok']])->post(route('cooking.restart', $recipe->slug))->assertStatus(419)->assertSessionHas($key, ['krok']);
    }

    public function test_anulowanie_czesciowego_postepu_i_wyjscie_niczego_nie_kasuja(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $step = RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Wymieszaj.']);
        RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 1, 'instruction' => 'Upiecz.']);
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $url = route('cooking.show', [$recipe->slug, 'krok' => 2]);
        $this->withSession([$key => [$step->id]])->get($url)->assertSee('Zostaw odhaczenia')->assertSessionHas($key, [$step->id]);
        $this->get($url)->assertSessionHas($key, [$step->id]);
        $this->get(route('recipes.show', $recipe->slug))->assertSessionHas($key, [$step->id]);
        $this->get(route('cooking.show', $recipe->slug))->assertSee('Zrobione ✓')->assertSessionHas($key, [$step->id]);
    }
}
