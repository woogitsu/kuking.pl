<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_edits_title_through_form_and_stranger_is_denied(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id, 'body' => null]);
        $this->actingAs($owner)->get(route('posts.edit', $post))->assertOk()->assertSee('Edytuj pytanie')->assertSee($post->title);
        $this->put(route('posts.update', $post), ['title' => 'Jak doprawić przesoloną zupę?', 'visibility' => 'public'])
            ->assertRedirect(route('questions.show', $post))->assertSessionHasNoErrors();
        $this->assertSame('Jak doprawić przesoloną zupę?', $post->fresh()->title);
        $this->actingAs($this->user('obcy'))->put(route('posts.update', $post), ['title' => 'Cudza zmiana pytania', 'visibility' => 'public'])->assertForbidden();
        $this->assertSame('Jak doprawić przesoloną zupę?', $post->fresh()->title);
    }

    public function test_invalid_title_returns_input_without_changing_saved_question(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $this->actingAs($owner)->from(route('posts.edit', $post))->put(route('posts.update', $post), [
            'title' => 'Zupa?', 'body' => 'Nowy opis do zachowania', 'visibility' => 'public',
        ])->assertRedirect(route('posts.edit', $post))->assertSessionHasErrors('title')
            ->assertSessionHasInput('title', 'Zupa?')->assertSessionHasInput('body', 'Nowy opis do zachowania');
        $this->assertSame($post->title, $post->fresh()->title);
    }

    public function test_title_only_question_can_be_edited_without_losing_its_kind(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = Post::factory()->question()->create(['body' => null]);
        $changed = app(EditPost::class)->handle($post->author, $post, null, 'public', questionTitle: 'Jak doprawić przesoloną zupę?');
        $this->assertSame('Jak doprawić przesoloną zupę?', $changed->title);
        $this->assertSame(Post::KIND_QUESTION, $changed->kind);
        $this->assertNull($changed->body);
    }

    public function test_edit_cannot_bypass_three_tag_limit_and_rolls_back_title(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = Post::factory()->question()->create();
        $title = $post->title;
        try {
            app(EditPost::class)->handle($post->author, $post, '#zupa #obiad #bulion #warzywa', 'public', questionTitle: 'Zmieniony tytuł pytania');
            $this->fail('Edycja musi respektować limit tagów.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('najwyżej 3 tagi', $e->getMessage());
        }
        $this->assertSame($title, $post->fresh()->title);
        $this->assertSame(0, $post->tags()->count());
    }

    public function test_too_many_inline_tags_points_to_tags_and_preserves_draft(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $body = '#zupa #obiad #bulion #warzywa';
        $this->actingAs($owner)->from(route('posts.edit', $post))->put(route('posts.update', $post), [
            'title' => 'Jak doprawić przesoloną zupę?', 'body' => $body, 'visibility' => 'public',
        ])->assertRedirect(route('posts.edit', $post))->assertSessionHasErrors('tagi')
            ->assertSessionDoesntHaveErrors('body')->assertSessionHasInput('body', $body);
        $this->assertSame($post->title, $post->fresh()->title);
        $this->assertSame(0, $post->tags()->count());
    }
}
