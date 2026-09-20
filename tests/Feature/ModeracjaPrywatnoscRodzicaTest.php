<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Posts\Actions\EditPost;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModeracjaPrywatnoscRodzicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        config(['kuking.moderation.model.klucz' => 'test', 'kuking.moderation.model.ocenia_zdjecia' => false]);
        Http::fake(['*' => Http::response(['results' => [['category_scores' => ['hate' => 0.95]]]])]);
    }

    public static function parents(): iterable
    {
        foreach (['post', 'recipe', 'cooked'] as $type) {
            foreach (['private', 'hidden', 'deleted', 'banned', 'pending_delete', 'public', 'followers', 'suspended', 'erased'] as $state) {
                yield "$type $state" => [$type, $state];
            }
        }
    }

    /** Zmiana przed wykonaniem zadania; nie jest to pomiar dwóch równoległych połączeń. */
    #[DataProvider('parents')]
    public function test_analiza_czyta_aktualna_dostepnosc_rodzica(string $type, string $state): void
    {
        $owner = $this->user();
        $parent = $type === 'post' ? Post::factory()->create(['author_id' => $owner->id]) : Recipe::factory()->create(['author_id' => $owner->id]);
        $subject = $type === 'cooked' ? CookedEvent::factory()->create(['recipe_id' => $parent->id]) : $parent;
        $comment = app(PublishComment::class)->handle($this->user(), $subject, 'ZNACZNIK_PRYWATNEJ_TRESCI zarabiaj z domu');
        Queue::assertPushed(PrzeanalizujTresc::class);

        if ($state === 'deleted') {
            $parent->delete();
        } elseif (in_array($state, ['private', 'public', 'followers'])) {
            if ($parent instanceof Post) {
                app(EditPost::class)->handle($parent, $parent->body, $state);
            } else {
                $parent->update(['visibility' => $state]);
            }
        } elseif ($state === 'hidden') {
            $parent->update(['status' => $state]);
        } else {
            $owner->forceFill(['status' => $state, 'data_erased_at' => $state === 'erased' ? now() : null])->save();
        }

        $this->assertSame(Comment::STATUS_PUBLISHED, $comment->fresh()->status);
        app()->call([new PrzeanalizujTresc('comment', $comment->id), 'handle']);

        if (in_array($state, ['public', 'followers', 'suspended', 'erased'])) {
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => str_contains($request['input'][0]['text'], 'ZNACZNIK_PRYWATNEJ_TRESCI'));
            $this->assertSame(1, Report::where('target_id', $comment->id)->count());
        } else {
            Http::assertNothingSent();
            $this->assertSame(0, Report::where('target_id', $comment->id)->count());
        }
    }

    public static function cooks(): array
    {
        return [['pending_delete', false], ['banned', true], ['suspended', true], ['erased', true], ['deleted', false]];
    }

    #[DataProvider('cooks')]
    public function test_wykonanie_zachowuje_granice_swojej_policy(string $state, bool $allowed): void
    {
        $event = CookedEvent::factory()->create();
        $comment = app(PublishComment::class)->handle($this->user(), $event, 'Znacznik komentarza pod wykonaniem');
        if ($state === 'deleted') {
            $event->delete();
        } else {
            $event->user->forceFill(['status' => $state, 'data_erased_at' => $state === 'erased' ? now() : null])->save();
        }
        app()->call([new PrzeanalizujTresc('comment', $comment->id), 'handle']);
        Http::assertSentCount($allowed ? 1 : 0);
        $this->assertSame($allowed ? 1 : 0, Report::where('target_id', $comment->id)->count());
    }
}
