<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Zapis rzeczywistych odpowiedzi do oglądu układu, bez utrwalania fixture. */
class EkranyDoOgladuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapisz_ekrany(): void
    {
        $author = $this->user();
        $post = Post::factory()->create(['author_id' => $author->id]);
        $comment = Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'created_at' => now()->subMinutes(16)]);
        $response = $this->actingAs($author)->put(route('comments.update', $comment), ['body' => "Dodałam szklankę wody i gotowałam jeszcze dziesięć minut.\nNastępnym razem dodam mniej soli."])->assertForbidden();
        $this->savePage('910', $response->getContent());
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Zupa na niedzielę']);
        $step = RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Wymieszaj składniki.']);
        $response = $this->withSession(['gotowanie.'.$recipe->id.'.zrobione' => [$step->id]])->get(route('cooking.show', $recipe->slug))->assertOk();
        $this->savePage('903', $response->getContent());
    }

    private function savePage(string $number, string $html): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = $manifest['resources/css/app.css']['file'];
        $html = str_replace('</head>', '<link rel="stylesheet" href="/build/'.$css.'"></head>', $html);
        file_put_contents(public_path('qa-'.$number.'.html'), $html);
    }
}
