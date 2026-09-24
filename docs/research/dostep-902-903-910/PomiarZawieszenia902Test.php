<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PomiarZawieszenia902Test extends TestCase
{
    use RefreshDatabase;

    public function test_pomiar(): void
    {
        $author = $this->user();
        $reader = $this->user();
        $reader->suspend(now()->addDay());
        $post = Post::factory()->create(['author_id' => $author->id]);
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Wymieszaj.']);
        $this->actingAs($reader);
        $this->get($post->url())->assertOk()->assertSee('Wyślij komentarz');
        $this->assertFalse($reader->can('comment', $post));
        $this->post(route('posts.comment', $post), ['body' => 'Pomiar komentarza'])->assertRedirect()->assertSessionHasErrors('konto');
        $this->get(route('profile.show', $author->profile->username))->assertOk()->assertSee(route('social.follow', $author->profile->username), false);
        $this->assertFalse($reader->can('follow', $author));
        $this->post(route('social.follow', $author->profile->username))->assertRedirect()->assertSessionHasErrors('konto');
        $this->get(route('recipes.show', $recipe->slug))->assertOk()->assertSee(route('collections.save', $recipe->slug), false);
        $this->post(route('collections.save', $recipe->slug))->assertRedirect()->assertSessionHasErrors('konto');
        $this->get(route('cooking.show', $recipe->slug))->assertOk()->assertSee('Oznacz krok jako zrobiony');
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1])->assertRedirect()->assertSessionHasErrors('konto');
    }
}
