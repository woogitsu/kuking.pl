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

    public function test_guest_reaches_tag_filter_by_link_from_question_and_keeps_it_with_unanswered(): void
    {
        config(['kuking.questions.enabled' => true]);
        $tag = Tag::factory()->create(['name' => 'Zupy domowe']);
        $hidden = Tag::factory()->hidden()->create(['name' => 'Ukryty temat']);
        $question = Post::factory()->question()->create(['title' => 'Jak uratować przesoloną zupę?']);
        $question->tags()->attach([$tag->id => ['position' => 0], $hidden->id => ['position' => 1]]);
        $answered = Post::factory()->question()->create(['title' => 'Czym zagęścić zupę krem?']);
        $answered->tags()->attach($tag);
        Comment::factory()->create(['post_id' => $answered->id]);
        $untagged = Post::factory()->question()->create(['title' => 'Jak długo wyrabiać ciasto?']);
        $dish = Post::factory()->create(['body' => 'Rosół z niedzieli, tag zupy domowe']);
        $dish->tags()->attach($tag);

        $page = $this->get(route('questions.show', $question))->assertOk()
            ->assertSee('Inne pytania na ten temat')
            ->assertSee('Pytania: Zupy domowe')
            ->assertDontSee('Pytania: Ukryty temat')
            ->getContent();
        $this->assertSame(1, preg_match('/<a class="chip" href="([^"]+)">Pytania: Zupy domowe<\/a>/', $page, $link));
        $href = html_entity_decode($link[1]);
        $this->assertSame(route('questions.index', ['tag' => $tag->slug]), $href);

        // Zwykły link z HTML, nie ręcznie złożony adres.
        $list = $this->get($href)->assertOk()
            ->assertSee($question->title)->assertSee($answered->title)
            ->assertDontSee($untagged->title)
            ->assertSee('Tag: Zupy domowe.')
            ->assertSee('<input type="hidden" name="tag" value="'.$tag->slug.'">', false)
            ->assertSee('href="'.e(route('questions.index', ['filtr' => 'najnowsze'])).'"', false)
            ->getContent();
        $this->assertSame(1, preg_match('/id="pytania-bez-odpowiedzi"[^>]*href="([^"]+)"/', $list, $queue));

        $this->get(html_entity_decode($queue[1]))->assertOk()
            ->assertSee($question->title)->assertDontSee($answered->title)
            ->assertDontSee($untagged->title)->assertSee('Tag: Zupy domowe.');
    }

    public function test_question_without_active_tags_has_no_tag_filter_section(): void
    {
        config(['kuking.questions.enabled' => true]);
        $question = Post::factory()->question()->create();
        $question->tags()->attach(Tag::factory()->hidden()->create());
        $this->get(route('questions.show', $question))->assertOk()->assertDontSee('Inne pytania na ten temat');
    }
}
