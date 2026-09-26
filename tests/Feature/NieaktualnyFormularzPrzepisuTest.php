<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class NieaktualnyFormularzPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_formularz_nie_nadpisuje_nowszego_zapisu_a_aktualny_moze_zapisac(): void
    {
        // Oba zapisy dostają ten sam znacznik czasu; updated_at nie byłby
        // wiarygodną rewizją przy rozdzielczości sekundowej tej kolumny.
        $this->travelTo(now()->startOfSecond());
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Zupa domowa'],
            [['text' => 'marchew']], [['instruction' => 'Gotuj.']], true);
        $this->actingAs($author);
        $edit = route('recipes.edit', $recipe);
        $html = $this->get($edit)->assertOk()->getContent();
        $this->assertStringContainsString('name="content_revision" value="0"', $html);

        $base = ['action' => 'publish', 'visibility' => 'public', 'content_revision' => 0,
            'steps' => [['instruction' => 'Gotuj.']]];
        $this->from($edit)->put(route('recipes.update', $recipe), [
            ...array_diff_key($base, ['content_revision' => true]), 'title' => 'Bez rewizji',
        ])->assertSessionHasErrors('content_revision');
        $this->assertSame('Zupa domowa', $recipe->fresh()->title);

        $this->from($edit)->put(route('recipes.update', $recipe), [
            ...$base, 'title' => 'Zupa od Ani', 'ingredients' => [['text' => 'pietruszka']],
            'steps' => [['instruction' => 'Gotuj od Ani.']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $recipe->fresh()->content_revision);

        $this->from($edit)->put(route('recipes.update', $recipe), [
            ...$base, 'title' => 'Stara zupa', 'ingredients' => [['text' => 'cebula']],
            'steps' => [['instruction' => 'Gotuj starym sposobem.']],
        ])->assertRedirect($edit)->assertSessionHasErrors('title')
            ->assertSessionHasInput('title', 'Stara zupa')
            ->assertSessionHasInput('steps', [['instruction' => 'Gotuj starym sposobem.']])
            ->assertSessionHasInput('content_revision', 0);
        $this->assertSame('Zupa od Ani', $recipe->fresh()->title);
        $this->assertSame(['pietruszka'], $recipe->fresh()->ingredients->pluck('ingredient_text')->all());
        $this->assertSame(['Gotuj od Ani.'], $recipe->steps()->pluck('instruction')->all());
        $this->assertSame(1, $recipe->fresh()->content_revision);

        $this->from($edit)->put(route('recipes.update', $recipe), [
            ...$base, 'content_revision' => 1, 'title' => 'Świeża zupa',
            'ingredients' => [['text' => 'pietruszka']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Świeża zupa', $recipe->fresh()->title);
        $this->assertSame(2, $recipe->fresh()->content_revision);
    }

    public function test_odczyt_krokow_po_blokadzie_uzywa_aktualnej_mapy(): void
    {
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Zupa domowa'], [],
            [['instruction' => 'Stary krok.']]);
        $old = $recipe->steps()->sole();
        $newId = (string) Str::uuid();
        $armed = true;

        // Zmiana wpada dokładnie po pierwszym SELECT recipe_steps, zanim
        // PublishRecipe dostanie blokadę przepisu. Stara mapa nie zna newId.
        DB::listen(function ($query) use (&$armed, $old, $recipe, $newId): void {
            if (! $armed || ! preg_match('/^select .*from "recipe_steps"/i', $query->sql)) {
                return;
            }
            $armed = false;
            $old->delete();
            DB::table('recipe_steps')->insert([
                'id' => $newId, 'recipe_id' => $recipe->id, 'position' => 0,
                'instruction' => 'Nowy krok.',
            ]);
        });

        app(PublishRecipe::class)->handle($author, ['title' => 'Zupa poprawiona'], [],
            [['id' => $newId, 'instruction' => 'Nowy krok poprawiony.']], false, $recipe);

        $this->assertFalse($armed, 'Test musi wstrzyknąć zmianę po pierwszym odczycie kroków.');
        $this->assertSame([$newId], $recipe->steps()->pluck('id')->all());
        $this->assertSame('Nowy krok poprawiony.', $recipe->steps()->sole()->instruction);
    }

    public function test_drugi_kreator_nie_nadpisuje_pierwszego(): void
    {
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Zupa domowa'], [],
            [['instruction' => 'Gotuj.']], true);

        $first = Livewire::actingAs($author)->test('recipe-wizard', ['recipeId' => $recipe->id]);
        $second = Livewire::actingAs($author)->test('recipe-wizard', ['recipeId' => $recipe->id]);
        $first->set('title', 'Zupa poprawiona')->assertSet('saveState', 'saved');
        $second->set('title', 'Stara poprawka')->assertSet('saveState', 'error');

        $this->assertSame('Zupa poprawiona', $recipe->fresh()->title);
        $this->assertSame(1, $recipe->fresh()->content_revision);
        $second->assertSet('title', 'Stara poprawka');
    }
}
