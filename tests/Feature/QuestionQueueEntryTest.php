<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionQueueEntryTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $url = '/pytania'): \DOMXPath
    {
        $html = $this->get($url)->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    public function test_department_explains_its_name_in_main_content(): void
    {
        config(['kuking.questions.enabled' => true]);
        $page = $this->page();
        $this->assertSame('Poradźcie', $page->evaluate('string(//main//h1)'));
        $this->assertSame(1, $page->query('//main//p[text()="Ktoś to już robił i chętnie powie, jak."]')->length);
        $this->assertSame(1, $page->query('//main//p[text()="Pytanie do innych jest w porządku."]')->length);
    }

    public function test_unanswered_entry_is_visible_before_questions_and_opens_actionable_list(): void
    {
        config(['kuking.questions.enabled' => true, 'kuking.feed.page_size' => 1]);
        $old = Post::factory()->question()->create(['published_at' => now()->subDays(2)]);
        $new = Post::factory()->question()->create(['published_at' => now()->subDay()]);
        $answered = Post::factory()->question()->create();
        Comment::factory()->create(['post_id' => $answered->id]);
        Post::factory()->question()->private()->create();
        $page = $this->page();
        $entry = $page->query('//main//a[@id="pytania-bez-odpowiedzi"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $entry);
        $this->assertSame('Czeka na odpowiedź (2)', trim($entry->textContent));
        $this->assertSame(0, $page->query('//a[@id="pytania-bez-odpowiedzi"]/preceding::article')->length);
        $list = $this->page($entry->getAttribute('href'));
        $this->assertSame($new->title, $list->evaluate('string(//main//article//h2/a)'));
        $this->assertSame($new->url().'#komentarze', $list->evaluate('string(//main//article//a[normalize-space(.)="Otwórz i odpowiedz"]/@href)'));
        $next = $list->evaluate('string(//main//a[contains(@href,"cursor=")]/@href)');
        $this->assertNotEmpty($next);
        $second = $this->page($next);
        $this->assertSame($old->title, $second->evaluate('string(//main//article//h2/a)'));
        $this->assertSame('Czeka na odpowiedź (2)', trim($second->evaluate('string(//a[@id="pytania-bez-odpowiedzi"])')));
    }

    public function test_counter_respects_tag_visibility_and_response_changes(): void
    {
        config(['kuking.questions.enabled' => true]);
        $viewer = $this->user('czytelnik');
        $blocked = $this->user('zablokowany');
        $this->actingAs($viewer);
        $tag = Tag::factory()->create();
        $question = Post::factory()->question()->create();
        $question->tags()->attach($tag);
        Post::factory()->question()->create();
        $hidden = Post::factory()->question()->create(['author_id' => $blocked->id]);
        $hidden->tags()->attach($tag);
        app(BlockUser::class)->handle($viewer, $blocked);
        $url = '/pytania?tag='.$tag->slug;
        $page = $this->page($url);
        $this->assertSame('Czeka na odpowiedź (1)', trim($page->evaluate('string(//a[@id="pytania-bez-odpowiedzi"])')));
        $link = $page->evaluate('string(//a[@id="pytania-bez-odpowiedzi"]/@href)');
        $this->assertStringContainsString('tag='.$tag->slug, $link);
        $answer = Comment::factory()->create(['post_id' => $question->id]);
        $this->assertSame('Czeka na odpowiedź (0)', trim($this->page($url)->evaluate('string(//a[@id="pytania-bez-odpowiedzi"])')));
        $answer->update(['status' => Comment::STATUS_HIDDEN]);
        $this->assertSame('Czeka na odpowiedź (1)', trim($this->page($url)->evaluate('string(//a[@id="pytania-bez-odpowiedzi"])')));
        config(['kuking.questions.enabled' => false]);
        $this->get($link)->assertNotFound();
    }
}
