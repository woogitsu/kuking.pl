<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\QuestionNotificationContext;
use App\Models\Notification;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionNotificationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.questions.enabled' => true]);
    }

    public function test_two_questions_with_identical_answers_show_their_current_titles(): void
    {
        $owner = $this->user();
        $actor = $this->user();
        $titles = ['Dlaczego ciasto opada?', 'Jak zagęścić sos grzybowy?'];
        foreach ($titles as $title) {
            $question = Post::factory()->question()->create(['author_id' => $owner->id, 'title' => $title, 'body' => null]);
            app(PublishComment::class)->handle($actor, $question, 'Dodaj trochę mąki.');
        }
        $response = $this->actingAs($owner)->get(route('notifications.index'))->assertOk();
        $rows = $this->rows($response->getContent());
        $this->assertCount(2, $rows);
        foreach ($titles as $title) {
            $matching = array_filter($rows, fn ($row) => str_contains($row, $title));
            $this->assertCount(1, $matching, 'Każde pytanie ma własny tytuł na liście powiadomień.');
            $this->assertStringContainsString('Dodaj trochę mąki.', reset($matching));
        }
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_thread_reply_and_old_record_use_live_escaped_title_without_json_copy(): void
    {
        $owner = $this->user();
        $actor = $this->user();
        $question = Post::factory()->question()->create(['author_id' => $owner->id]);
        $answer = app(PublishComment::class)->handle($actor, $question, 'Zostaw na godzinę.');
        app(PublishComment::class)->handle($owner, $question, 'Czy w lodówce?', $answer);
        $title = 'Żółć <em>ciasto</em> & sos '.str_repeat('ą', 140);
        $question->update(['title' => $title]);
        foreach (Notification::all() as $notification) {
            $this->assertArrayNotHasKey('question_title', $notification->data);
        }
        foreach ([$owner, $actor] as $recipient) {
            $html = $this->actingAs($recipient)->get(route('notifications.index'))->assertOk()->getContent();
            $this->assertStringContainsString($title, implode(' ', $this->rows($html)));
            $this->assertStringNotContainsString('<em>ciasto</em>', $html);
            $this->assertStringContainsString(e($title), $html);
        }
        // Rekord bez comment_id nadal odcina istniejący filtr dostępności.
        Notification::create(['user_id' => $owner->id, 'actor_id' => $actor->id,
            'type' => Notification::TYPE_REPLY, 'data' => ['excerpt' => 'Dawna odpowiedź bez kontekstu.']]);
        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->assertDontSee('Dawna odpowiedź bez kontekstu.')->assertSee(e($title), false);
    }

    public function test_lost_access_deletion_and_disabled_feature_do_not_expose_question_titles(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $question = Post::factory()->question()->create(['author_id' => $owner->id, 'title' => 'Poufne pytanie o zakwas?']);
        $answer = app(PublishComment::class)->handle($recipient, $question, 'Pierwsza odpowiedź.');
        app(PublishComment::class)->handle($owner, $question, 'Dziękuję za pomoc.', $answer);
        Notification::create(['user_id' => $recipient->id, 'type' => Notification::TYPE_WELCOME, 'data' => []]);
        $this->actingAs($recipient)->get(route('notifications.index'))->assertOk()->assertSee($question->title);
        foreach (['private', 'followers'] as $visibility) {
            $question->update(['visibility' => $visibility]);
            $this->get(route('notifications.index'))->assertOk()->assertDontSee($question->title)->assertSee('Witamy w Kuking');
        }
        $question->update(['visibility' => 'public']);
        config(['kuking.questions.enabled' => false]);
        $this->get(route('notifications.index'))->assertOk()->assertDontSee($question->title)->assertSee('Witamy w Kuking');
        config(['kuking.questions.enabled' => true]);
        $question->delete();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee($question->title)->assertSee('Witamy w Kuking');
    }

    public function test_query_count_does_not_grow_with_number_of_questions(): void
    {
        $owner = $this->user();
        $actor = $this->user();
        $this->actingAs($owner);
        // Mierzymy koszt SAMYCH TYTUŁÓW — `QuestionNotificationContext::titles()`
        // na tych powiadomieniach, które dostaje kontroler — a nie całej odpowiedzi:
        // pełna strona rośnie z liczbą wierszy niezależnie od tej zmiany, bo
        // `Notification::adresDocelowy()` liczy adres komentarza per wiersz
        // (#759; zbiorcza wersja była w zamkniętym #1257).
        $counts = [];
        foreach ([2, 18] as $amount) {
            for ($i = 0; $i < $amount; $i++) {
                $question = Post::factory()->question()->create(['author_id' => $owner->id]);
                app(PublishComment::class)->handle($actor, $question, 'Dodaj trochę mąki.');
            }
            $page = Notification::query()->where('user_id', $owner->id)->get()->all();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $titles = app(QuestionNotificationContext::class)->titles($page, $owner);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $expected = array_sum(array_slice([2, 18], 0, count($counts)));
            $this->assertCount($expected, $titles);
            $response = $this->get(route('notifications.index'))->assertOk();
            $this->assertCount($expected, $this->rows($response->getContent()));
            $response->assertSee($question->title);
        }
        $this->assertSame($counts[0], $counts[1], 'Tytuły pytań nie mogą dokładać zapytania na wiersz: '.json_encode($counts));
        $this->assertLessThanOrEqual(2, $counts[1], 'Tytuły pytań to najwyżej dwa zbiorcze odczyty: '.json_encode($counts));
    }

    private function rows(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new \DOMXPath($dom))->query('//main//article');
        $this->assertGreaterThan(0, $nodes->length);

        return array_map(fn ($node) => $node->textContent, iterator_to_array($nodes));
    }
}
