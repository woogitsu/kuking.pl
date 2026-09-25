<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Feed\FollowingFeed;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionsFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_off_questions_hides_them_from_profiles_and_following_feed(): void
    {
        $owner = $this->user('autor_pytania');
        $viewer = $this->user('widz_pytania');
        $viewer->following()->attach($owner->id, ['created_at' => now()]);
        $question = Post::factory()->question()->create(['author_id' => $owner->id, 'body' => 'Ukrywana tresc pytania']);
        $feed = app(FollowingFeed::class);

        config(['kuking.questions.enabled' => true]);
        $this->assertFalse($feed->isEmptyFor($viewer));
        $this->assertCount(1, $feed->paginate($viewer)->items());
        $this->get(route('profile.show', 'autor_pytania'))->assertOk()->assertSee($question->body);

        config(['kuking.questions.enabled' => false]);
        $this->assertTrue($feed->isEmptyFor($viewer));
        $this->assertCount(0, $feed->paginate($viewer)->items());
        $this->get(route('profile.show', 'autor_pytania'))->assertOk()->assertDontSee($question->body);
        $this->actingAs($owner)->get(route('profile.show', 'autor_pytania'))->assertOk()->assertDontSee($question->body);
        $this->assertDatabaseHas('posts', ['id' => $question->id]);
    }

    public function test_switching_off_questions_hides_existing_answer_notifications(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('odbiorca_pytania');
        $respondent = $this->user('autor_odpowiedzi');
        $question = Post::factory()->question()->create(['author_id' => $owner->id]);
        app(PublishComment::class)->handle(
            author: $respondent, subject: $question, body: 'Dolej niesolonego bulionu.',
        );
        $this->actingAs($owner)->get('/powiadomienia')->assertOk()->assertSee('Dolej niesolonego bulionu.');
        config(['kuking.questions.enabled' => false]);
        $this->get('/powiadomienia')->assertOk()->assertDontSee('Dolej niesolonego bulionu.');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_disabled_questions_are_not_readable_through_the_existing_post_address(): void
    {
        config(['kuking.questions.enabled' => false]);
        $question = Post::factory()->question()->create(['body' => 'Pytanie schowane za flagą']);
        $dish = Post::factory()->create(['body' => 'Zwykły wpis pozostaje dostępny']);

        $this->get(route('posts.show', $question))->assertForbidden();
        $this->get(route('posts.show', $dish))->assertOk()->assertSee($dish->body);
        $this->assertDatabaseHas('posts', ['id' => $question->id, 'body' => $question->body]);
    }

    public function test_disabled_questions_are_excluded_from_public_and_viewer_lists_without_deleting_data(): void
    {
        config(['kuking.questions.enabled' => false]);
        $question = Post::factory()->question()->create();
        $dish = Post::factory()->create();

        $this->assertFalse(Post::query()->publiclyVisible()->whereKey($question)->exists());
        $this->assertFalse(Post::query()->widoczneDla(null)->whereKey($question)->exists());
        $this->assertTrue(Post::query()->publiclyVisible()->whereKey($dish)->exists());
        $this->assertTrue(Post::query()->whereKey($question)->exists());
    }

    public function test_enabled_questions_still_respect_private_visibility(): void
    {
        config(['kuking.questions.enabled' => true]);
        $question = Post::factory()->question()->create(['body' => 'Publiczne pytanie o gotowanie']);
        $private = Post::factory()->question()->private()->create();

        // Pytanie ma jeden adres (#968): stary `/wpisy/{id}` przekierowuje.
        $this->get(route('posts.show', $question))->assertStatus(301)->assertRedirect(route('questions.show', $question));
        $this->get(route('questions.show', $question))->assertOk()->assertSee($question->body);
        $this->get(route('posts.show', $private))->assertForbidden();
        $this->assertTrue(Post::query()->publiclyVisible()->whereKey($question)->exists());
        $this->assertFalse(Post::query()->widoczneDla(null)->whereKey($private)->exists());
    }
}
