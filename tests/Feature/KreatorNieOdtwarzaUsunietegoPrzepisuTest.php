<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KreatorNieOdtwarzaUsunietegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public static function actions(): array
    {
        return [['autosave'], ['saveDraft'], ['publish']];
    }

    /** Dwa żądania po kolei, nie pomiar wyścigu dwóch połączeń. */
    #[DataProvider('actions')]
    public function test_usuniecie_w_drugiej_karcie_nie_zamienia_edycji_w_nowy_przepis(string $action): void
    {
        $user = $this->user();
        $recipe = Recipe::factory()->draft()->create(['author_id' => $user->id]);
        $recipe->steps()->create(['position' => 0, 'instruction' => 'Zagotuj wodę.']);
        $component = Livewire::actingAs($user)->test('recipe-wizard', ['recipeId' => $recipe->id]);

        $this->actingAs($user)->delete(route('recipes.destroy', $recipe->slug))->assertRedirect();
        $posts = Post::count();

        if ($action === 'autosave') {
            $component->set('summary', 'Tekst wpisany po usunięciu.');
        } else {
            $component->call($action);
        }

        $this->assertSame(1, Recipe::withTrashed()->count(), 'Nie wolno tworzyć zastępczego przepisu.');
        $this->assertSoftDeleted($recipe);
        $this->assertSame($posts, Post::count());
        $component->assertSet('recipeId', $recipe->id)
            ->assertSet('saveState', 'error')
            ->assertSee('Skopiuj wpisany tekst');
        if ($action === 'autosave') {
            $component->assertSet('summary', 'Tekst wpisany po usunięciu.');
        }
    }

    public function test_nowy_szkic_i_edycja_istniejacego_nadal_zapisuja(): void
    {
        $component = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Zupa na poniedziałek')->assertSet('saveState', 'saved');
        $id = $component->get('recipeId');
        $component->set('summary', 'Ze świeżym koperkiem.')->assertSet('recipeId', $id);
        $this->assertSame(1, Recipe::count());
        $this->assertSame('Ze świeżym koperkiem.', Recipe::findOrFail($id)->summary);
    }
}
