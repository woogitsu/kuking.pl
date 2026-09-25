<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Moderation\UnansweredContent;
use App\Domain\Questions\QuestionList;
use App\Domain\Sharing\Udostepnianie;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_answer_preserves_children_but_removes_answer_from_counts_schema_and_queue(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy_usuniecie');
        $other = $this->user('odpowiadajacy_usuniecie');
        $host = $this->user('gospodarz_usuniecie');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $answer = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $other->id, 'body' => 'Dolej bulionu.']);
        $child = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id, 'parent_id' => $answer->id, 'body' => 'Rozmowa pozostaje.']);
        $queue = app(UnansweredContent::class);
        $this->assertFalse($queue->questions($host)->whereKey($post)->exists());
        $this->actingAs($other)->delete(route('comments.destroy', $answer))->assertRedirect();
        $this->assertNotNull($answer->fresh()->getAttribute('body_removed_at'));
        $this->assertDatabaseHas('comments', ['id' => $child->id, 'body' => 'Rozmowa pozostaje.']);
        $this->get(route('questions.show', $post))->assertOk()->assertSee('Rozmowa pozostaje.')
            ->assertDontSee('Dolej bulionu.')->assertViewHas('komentarzyRazem', 0)
            ->assertSee('"suggestedAnswer":[]', false)->assertSee('"answerCount":0', false);
        $this->assertTrue($queue->questions($host)->whereKey($post)->exists());
        $listed = app(QuestionList::class)->query($owner, true)->whereKey($post)->sole();
        $this->assertSame(0, $listed->answer_count);
        $card = Post::query()->withVisibleCommentCount($owner)->findOrFail($post->id);
        $this->assertSame(0, $card->comments_count);
        $dish = Post::factory()->create();
        Comment::factory()->create(['post_id' => $dish->id, 'body_removed_at' => now()]);
        $this->assertSame(1, Post::query()->withVisibleCommentCount($owner)->findOrFail($dish->id)->comments_count);
        $this->put(route('comments.update', $answer), ['body' => 'Przywracanie usuniętej odpowiedzi'])->assertForbidden();
    }

    public function test_sharing_uses_question_title_and_canonical_url_but_hides_private_questions(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = Post::factory()->question()->create(['title' => 'Jak uratować przesoloną zupę?']);
        $sharing = app(Udostepnianie::class);
        $this->assertSame(route('questions.show', $post), $sharing->adres($post));
        $this->assertSame($post->title, $sharing->tytul($post));
        foreach ($sharing->drogi($post) as $channel) {
            $this->assertStringContainsString(rawurlencode(route('questions.show', $post)), $channel['adres']);
        }
        $this->get(route('questions.show', $post))->assertOk()
            ->assertSee('data-podziel-adres="'.route('questions.show', $post).'"', false)
            ->assertSee('data-podziel-tytul="'.$post->title.'"', false)
            ->assertSee('Wyślij to pytanie');
        $post->update(['visibility' => 'private']);
        $this->assertFalse($sharing->wolnoWyslac($post));
        $this->actingAs($post->author)->get(route('questions.show', $post))->assertOk()->assertDontSee('data-podziel-sie', false);
    }

    public function test_answer_forms_have_unique_ids_and_failed_reply_stays_in_its_form(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $answer = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id]);
        $draft = str_repeat('Z', 4001);
        $this->actingAs($owner)->from(route('questions.show', $post))->post(route('posts.comment', $post), [
            'body' => $draft, 'parent_id' => $answer->id, '_wiersz' => 'odpowiedz-'.$answer->id,
        ])->assertSessionHasErrors('body');
        // Odbiór HTML korzysta z rzeczywistego błędu poprzedniego POST.
        // Utrwalamy flash między żądaniami testowymi; przepływ bez tego
        // przygotowania jest osobno sprawdzany w rzeczywistej przeglądarce.
        session()->keep(['errors', '_old_input']);
        session()->save();
        $html = $this->get(route('questions.show', $post))->assertOk()->getContent();
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        $this->assertCount(count(array_unique($ids[1])), $ids[1], 'IDs must be unique even in closed forms.');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $matching = $xpath->query('//textarea[text()="'.$draft.'"]');
        $this->assertSame(1, $matching->length);
        $this->assertSame('f-body-odpowiedz-'.$answer->id, $matching->item(0)->attributes->getNamedItem('id')->nodeValue);
        $this->assertSame(1, $xpath->query('//details[@open]')->length);
        $this->assertSame(1, $xpath->query('//details[@open]//textarea[@id="f-body-odpowiedz-'.$answer->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//div[@role="alert"]//a[@href="#f-body-odpowiedz-'.$answer->id.'"]')->length);
        $this->assertSame(1, $post->allComments()->count());
    }

    public function test_schema_answer_link_opens_the_page_containing_its_anchor(): void
    {
        config(['kuking.questions.enabled' => true, 'kuking.comments.page_size' => 1]);
        $post = Post::factory()->question()->create();
        Comment::factory()->create(['post_id' => $post->id, 'created_at' => now()->subMinute()]);
        $answer = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Druga odpowiedź na pytanie']);
        $html = $this->get(route('questions.show', ['post' => $post, 'komentarze' => 2]))->assertOk()->getContent();
        preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        $schema = collect(array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]))->firstWhere('@type', 'QAPage');
        $this->assertSame(2, $schema['mainEntity']['answerCount']);
        $this->assertCount(1, $schema['mainEntity']['suggestedAnswer']);
        $link = $schema['mainEntity']['suggestedAnswer'][0]['url'];
        $this->assertSame(route('questions.show', ['post' => $post, 'komentarze' => 2]).'#komentarz-'.$answer->id, $link);
        $this->get(explode('#', $link)[0])->assertOk()->assertSee('id="komentarz-'.$answer->id.'"', false)->assertSee($answer->body);
    }

    public function test_schema_contains_only_visible_top_level_answers_and_no_accepted_answer(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = Post::factory()->question()->create();
        $answer = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Widoczna odpowiedź']);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $answer->id, 'body' => 'Rozmowa pod odpowiedzią']);
        Comment::factory()->create(['post_id' => $post->id, 'status' => Comment::STATUS_HIDDEN, 'body' => 'Ukryta odpowiedź']);
        $html = $this->get(route('questions.show', $post))->assertOk()->getContent();
        preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        $schemas = array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
        $schema = collect($schemas)->firstWhere('@type', 'QAPage');
        $this->assertNotNull($schema);
        $this->assertSame(1, $schema['mainEntity']['answerCount']);
        $this->assertSame(['Widoczna odpowiedź'], array_column($schema['mainEntity']['suggestedAnswer'], 'text'));
        $this->assertArrayNotHasKey('acceptedAnswer', $schema['mainEntity']);
        $post->update(['visibility' => 'private']);
        $this->actingAs($post->author)->get(route('questions.show', $post))->assertOk()->assertDontSee('QAPage');
    }

    public function test_question_card_exposes_title_and_detail_does_not_truncate_body(): void
    {
        config(['kuking.questions.enabled' => true]);
        $body = str_repeat('Próbowałam zmienić proporcje składników. ', 35).'Ostatnie zdanie pytania.';
        $post = Post::factory()->question()->create(['body' => $body]);
        $post->load('author.profile', 'media', 'tags', 'recipe');
        $this->blade('<x-post-card :post="$post" />', ['post' => $post])
            ->assertSee($post->title)->assertSee(route('questions.show', $post))->assertSee('Napisz odpowiedź');
        $this->get(route('questions.show', $post))->assertOk()->assertSee('Ostatnie zdanie pytania.')
            ->assertDontSee('Czytaj dalej')
            ->assertSee('href="'.route('questions.show', $post).'#komentarze"', false)
            ->assertSee('id="komentarze"', false);
    }

    public function test_answer_notifies_question_author_without_changing_nested_reply_semantics(): void
    {
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user('pytajacy');
        $respondent = $this->user('odpowiadajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $answer = app(PublishComment::class)->handle(author: $respondent, subject: $post, body: 'Dolej niesolonego bulionu.');
        $notice = Notification::query()->where('actor_id', $respondent->id)->sole();
        $this->assertSame(Notification::TYPE_COMMENT, $notice->type);
        $this->assertTrue($notice->data['question_answer']);
        $this->assertSame(route('questions.show', $post), $notice->data['url']);
        $this->actingAs($owner)->get('/powiadomienia')->assertOk()->assertSee('odpowiedź na Twoje pytanie.');

        app(PublishComment::class)->handle(author: $respondent, subject: $post, body: 'Najpierw spróbuj małej porcji.', parent: $answer);
        $replyNotice = Notification::query()->where('type', Notification::TYPE_REPLY)->sole();
        $this->assertFalse($replyNotice->data['question_answer']);
        $this->assertSame(1, $post->comments()->count());
    }

    public function test_zwykle_danie_z_komentarzem_nie_dostaje_schematu_qapage(): void
    {
        // Issue #372, „Testy obowiązkowe”: QAPage tylko dla pytania. Danie
        // z komentarzem najwyższego poziomu wygląda z danych jak pytanie
        // z odpowiedzią — różni je wyłącznie `kind`, więc pilnujemy strony
        // dania, a nie tylko tego, że `/pytania/{uuid}` dania nie wpuszcza.
        // Kontrola dodatnia: to samo wyszukiwanie znajduje QAPage na pytaniu.
        config(['kuking.questions.enabled' => true]);
        $typySchematu = function (string $html): array {
            preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);

            return array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR)['@type'] ?? null, $matches[1]);
        };

        $danie = Post::factory()->create();
        Comment::factory()->create(['post_id' => $danie->id, 'body' => 'Wygląda pysznie.']);
        $htmlDania = $this->get(route('posts.show', $danie))->assertOk()->assertSee('Wygląda pysznie.')->getContent();
        $this->assertNotContains('QAPage', $typySchematu($htmlDania));
        $this->assertStringNotContainsString('QAPage', $htmlDania);

        $pytanie = Post::factory()->question()->create();
        Comment::factory()->create(['post_id' => $pytanie->id, 'body' => 'Dolej bulionu.']);
        // Pytanie ma jeden adres (#968): `/wpisy/{id}` odpowiada 301 na `/pytania/{id}`.
        $this->assertContains('QAPage', $typySchematu($this->get($pytanie->url())->assertOk()->getContent()));
    }

    public function test_question_renders_title_and_counts_only_top_level_answers(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = Post::factory()->question()->create(['title' => 'Jak uratować przesoloną zupę?']);
        $answer = Comment::factory()->create(['post_id' => $post->id, 'body' => 'Dolej niesolonego bulionu.']);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $answer->id, 'body' => 'Dziękuję, spróbuję.']);
        Comment::factory()->create(['post_id' => $post->id, 'status' => Comment::STATUS_HIDDEN, 'body' => 'Ukryta odpowiedź']);

        $this->get(route('questions.show', $post))->assertOk()
            ->assertSee('<h1>'.$post->title.'</h1>', false)
            ->assertSee($answer->body)->assertSee('Dziękuję, spróbuję.')
            ->assertViewHas('komentarzyRazem', 1)
            ->assertViewHas('post', fn (Post $rendered) => $rendered->comments_count === 1)
            ->assertSee('Odpowiedzi (1)')->assertDontSee('Ukryta odpowiedź');
    }

    public function test_question_route_rejects_dishes_disabled_feature_and_private_questions(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->get(route('questions.show', Post::factory()->create()))->assertNotFound();
        $private = Post::factory()->question()->private()->create();
        $this->get(route('questions.show', $private))->assertForbidden();
        config(['kuking.questions.enabled' => false]);
        $this->get(route('questions.show', $private))->assertNotFound();
    }
}
