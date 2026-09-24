<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionHelpVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_explains_public_creation_and_later_visibility_editing(): void
    {
        config(['kuking.questions.enabled' => true]);
        $html = $this->get('/pomoc')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//h2[contains(., "Kto widzi")]/following-sibling::p[preceding-sibling::h2[1][contains(., "Kto widzi")]]');
        $this->assertGreaterThan(0, $nodes->length);
        $text = implode(' ', array_map(fn ($node) => $node->textContent, iterator_to_array($nodes)));
        $this->assertStringContainsString('Pytanie w Poradźcie publikujesz dla wszystkich', $text);
        $this->assertStringContainsString('Po publikacji możesz zmienić jego widoczność w edycji', $text);
        $this->assertStringContainsString('zwykły wpis', $text);
        $this->assertStringNotContainsString('Przy każdym wpisie', $text);
    }

    public function test_creation_is_public_but_owner_can_later_choose_private_or_followers(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user();
        $other = $this->user();
        $follower = $this->user();
        $follower->following()->attach($owner);
        $this->actingAs($owner)->get(route('questions.create'))->assertOk()->assertSee('Pytanie będzie widoczne dla wszystkich.')->assertDontSee('name="visibility"', false);
        $this->get(route('posts.create'))->assertOk()->assertSee('value="public"', false)->assertSee('value="followers"', false)->assertSee('value="private"', false);
        $this->post(route('questions.store'), ['title' => 'Jak uratować przesoloną zupę?', 'visibility' => 'private'])->assertRedirect()->assertSessionHasNoErrors();
        $question = Post::query()->sole();
        $this->assertSame('public', $question->visibility);
        foreach (['private', 'followers'] as $visibility) {
            $this->actingAs($owner)->put(route('posts.update', $question), ['title' => $question->title, 'visibility' => $visibility])->assertRedirect(route('questions.show', $question))->assertSessionHasNoErrors();
            $this->assertSame($visibility, $question->fresh()->visibility);
            $this->get(route('questions.show', $question))->assertOk();
            $this->actingAs($other)->get(route('questions.show', $question))->assertForbidden();
            $this->actingAs($follower)->get(route('questions.show', $question))->assertStatus($visibility === 'followers' ? 200 : 403);
            auth()->forgetGuards();
            $this->get(route('questions.show', $question))->assertForbidden();
        }
    }
}
