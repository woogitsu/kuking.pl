<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_is_reachable_from_discovery_and_publishing_only_when_enabled(): void
    {
        $user = $this->user('pytajacy');
        config(['kuking.questions.enabled' => false]);
        $this->get(route('discover'))->assertOk()->assertDontSee('href="'.route('questions.index').'"', false);
        $this->actingAs($user)->get('/dodaj')->assertOk()->assertDontSee('href="'.route('questions.create').'"', false);
        config(['kuking.questions.enabled' => true]);
        $this->get(route('discover'))->assertOk()->assertSee('href="'.route('questions.index').'"', false)
            ->assertSee('Poradźcie')->assertSee('Ktoś to już robił i chętnie powie, jak.');
        $this->get('/dodaj')->assertOk()->assertSee('href="'.route('questions.create').'"', false)->assertSee('Zadaj pytanie');
    }

    public function test_disabled_department_returns_not_found(): void
    {
        config(['kuking.questions.enabled' => false]);
        $this->get('/pytania')->assertNotFound();
    }

    public function test_guest_can_filter_questions_without_javascript(): void
    {
        config(['kuking.questions.enabled' => true]);
        $unanswered = Post::factory()->question()->create(['title' => 'Jak uratować przesoloną zupę?']);
        $answered = Post::factory()->question()->create(['title' => 'Jak długo wyrabiać ciasto?']);
        Comment::factory()->create(['post_id' => $answered->id]);
        $private = Post::factory()->question()->private()->create(['title' => 'Moje prywatne pytanie o obiad']);

        $this->get('/pytania')->assertOk()->assertSee($unanswered->title)->assertSee($answered->title)
            ->assertDontSee($private->title)->assertSee('name="filtr"', false);
        $this->get('/pytania?filtr=bez-odpowiedzi')->assertOk()->assertSee($unanswered->title)->assertDontSee($answered->title);
    }

    public function test_hidden_tag_is_not_exposed_by_filter(): void
    {
        config(['kuking.questions.enabled' => true]);
        $tag = Tag::factory()->hidden()->create();
        $this->get('/pytania?tag='.$tag->slug)->assertNotFound();
        $this->getJson('/pytania?filtr=ranking')->assertUnprocessable();
    }
}
