<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CommentModerationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['results' => [['category_scores' => ['hate' => 0.01]]]])]);
        \Illuminate\Support\Facades\Notification::fake();
        config(['kuking.moderation.model.klucz' => 'atrapa', 'kuking.moderation.model.ocenia_zdjecia' => false,
            'kuking.moderation.sygnaly.wlaczone' => true]);
    }

    private function comment(): Comment
    {
        $owner = $this->user('gospodarz');
        $author = $this->user('komentujacy');
        $post = Post::factory()->create(['author_id' => $owner->id, 'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC, 'published_at' => now()]);

        return Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id,
            'body' => 'Pierwotny komentarz.', 'created_at' => now()]);
    }

    public function test_edycja_po_analizie_ocenia_nowy_tekst(): void
    {
        $comment = $this->comment();
        dispatch_sync(new PrzeanalizujTresc('comment', $comment->id));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('reports', 0);
        Bus::fake([PrzeanalizujTresc::class]);
        $this->actingAs($comment->author)->put(route('comments.update', $comment), [
            'body' => 'Ciasta na zamówienie, tel. 600 100 200.',
        ])->assertRedirect();
        Bus::assertDispatched(PrzeanalizujTresc::class);
        foreach (Bus::dispatched(PrzeanalizujTresc::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
        Http::assertSent(fn ($r) => str_contains($r->body(), '600 100 200'));
        $this->assertDatabaseCount('reports', 1);
        $this->assertSame(Comment::STATUS_PUBLISHED, $comment->fresh()->status);
    }

    public function test_oczekujaca_analiza_i_szybkie_edycje_czytaja_ostatni_tekst(): void
    {
        $comment = $this->comment();
        Bus::fake([PrzeanalizujTresc::class]);
        PrzeanalizujTresc::dlaKomentarza($comment);
        $this->actingAs($comment->author);
        foreach (['Pierwsza zmiana.', 'Druga zmiana.', 'Druga zmiana.'] as $body) {
            $this->put(route('comments.update', $comment), ['body' => $body])->assertRedirect();
        }
        Bus::assertDispatchedTimes(PrzeanalizujTresc::class, 3);
        foreach (Bus::dispatched(PrzeanalizujTresc::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('Druga zmiana.', $request['input'][0]['text']);
        }
    }

    public function test_nowy_sygnal_uzupelnia_otwarte_oznaczenie(): void
    {
        $comment = $this->comment();
        dispatch_sync(new PrzeanalizujTresc('comment', $comment->id));
        $report = Report::create(['source' => Report::SOURCE_AUTOMAT, 'target_type' => 'comment',
            'target_id' => $comment->id, 'autor_tresci_id' => $comment->author_id, 'reason' => 'automat_model',
            'details' => 'Wcześniejszy sygnał.', 'status' => Report::STATUS_OPEN]);
        Bus::fake([PrzeanalizujTresc::class]);
        $this->actingAs($comment->author)->put(route('comments.update', $comment), [
            'body' => 'Ciasta na zamówienie, tel. 600 100 200.',
        ])->assertRedirect();
        foreach (Bus::dispatched(PrzeanalizujTresc::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
        $this->assertDatabaseCount('reports', 1);
        $this->assertStringContainsString('numer telefonu', $report->fresh()->details);
        $this->assertStringContainsString('Wcześniejszy sygnał.', $report->fresh()->details);
    }

    public function test_zamkniete_oznaczenie_nie_wraca_po_edycji(): void
    {
        $comment = $this->comment();
        $report = Report::create(['source' => Report::SOURCE_AUTOMAT, 'target_type' => 'comment',
            'target_id' => $comment->id, 'autor_tresci_id' => $comment->author_id, 'reason' => 'automat_model',
            'details' => 'Zamknięty sygnał.', 'status' => Report::STATUS_REJECTED]);
        Bus::fake([PrzeanalizujTresc::class]);
        $this->actingAs($comment->author)->put(route('comments.update', $comment), [
            'body' => 'Ciasta na zamówienie, tel. 600 100 200.',
        ])->assertRedirect();
        Bus::assertDispatchedTimes(PrzeanalizujTresc::class, 1);
        foreach (Bus::dispatched(PrzeanalizujTresc::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
        $this->assertSame(Report::STATUS_REJECTED, $report->fresh()->status);
        $this->assertSame('Zamknięty sygnał.', $report->fresh()->details);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_wylaczony_automat_i_usuniety_lub_ukryty_komentarz_nie_wysylaja_http(): void
    {
        $comment = $this->comment();
        $job = new PrzeanalizujTresc('comment', $comment->id);
        config(['kuking.moderation.sygnaly.wlaczone' => false]);
        $this->app->call([$job, 'handle']);
        config(['kuking.moderation.sygnaly.wlaczone' => true]);
        $comment->forceFill(['body' => 'Komentarz usunięty.', 'body_removed_at' => now()])->save();
        $this->app->call([$job, 'handle']);
        $comment->update(['status' => Comment::STATUS_HIDDEN]);
        $this->app->call([$job, 'handle']);
        $comment->delete();
        $this->app->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_stary_model_i_retencja_powiadomienia_nie_powtarzaja_usuniecia(): void
    {
        // Przeplot deterministyczny na jednym połączeniu, nie pomiar dwóch procesów.
        $comment = $this->comment();
        Comment::factory()->create(['author_id' => $comment->author_id, 'post_id' => $comment->post_id, 'parent_id' => $comment->id]);
        $stale = $comment->fresh();
        $actor = $comment->post->author;
        $action = app(DeleteComment::class);
        $action->handle($actor, $comment, 'Pierwszy powód.');
        $this->assertDatabaseCount('notifications', 1);
        Notification::query()->delete();
        $action->handle($actor, $stale, 'Drugi powód.');
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function replyCases(): array
    {
        return ['bez odpowiedzi' => [false], 'z odpowiedzia' => [true]];
    }

    #[DataProvider('replyCases')]
    public function test_awaria_powiadomienia_cofa_usuniecie_i_pozwala_ponowic(bool $withReply): void
    {
        $comment = $this->comment();
        $reply = $withReply ? Comment::factory()->create(['author_id' => $comment->author_id,
            'post_id' => $comment->post_id, 'parent_id' => $comment->id]) : null;
        $armed = true;
        $hit = false;
        Notification::creating(function () use (&$armed, &$hit, $comment): void {
            if ($armed) {
                $current = Comment::withTrashed()->findOrFail($comment->id);
                $this->assertTrue($current->deleted_at !== null || $current->body_removed_at !== null);
                $hit = true;
                throw new RuntimeException('AWARIA_911');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($comment->post->author)->delete(route('comments.destroy', $comment), ['reason' => 'Powód próby.']);
            $this->fail('Nie wyzwolono awarii.');
        } catch (RuntimeException $e) {
            $this->assertSame('AWARIA_911', $e->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertTrue($hit);
        $current = Comment::withTrashed()->findOrFail($comment->id);
        $this->assertNull($current->deleted_at, 'Usuniecie przetrwalo awarie powiadomienia.');
        $this->assertNull($current->body_removed_at, 'Zastapienie tresci przetrwalo awarie powiadomienia.');
        $this->assertSame('Pierwotny komentarz.', $current->body);
        $this->assertDatabaseCount('notifications', 0);
        $this->delete(route('comments.destroy', $comment), ['reason' => 'Powód próby.'])->assertRedirect();
        $this->assertDatabaseCount('notifications', 1);
        $notice = Notification::firstOrFail();
        $this->assertStringContainsString('Pierwotny komentarz.', $notice->data['message']);
        $this->assertStringContainsString('Powód próby.', $notice->data['message']);
        if ($reply) {
            $this->assertSame($comment->id, $reply->fresh()->parent_id);
            $this->assertNull($reply->fresh()->deleted_at);
            $this->delete(route('comments.destroy', $comment), ['reason' => 'Inny powód.'])->assertRedirect();
            $this->assertDatabaseCount('notifications', 1);
        }
    }

    public function test_ponowienie_sukcesu_nie_powiadamia_drugi_raz(): void
    {
        $comment = $this->comment();
        Comment::factory()->create(['author_id' => $comment->author_id, 'post_id' => $comment->post_id, 'parent_id' => $comment->id]);
        $this->actingAs($comment->post->author);
        $this->delete(route('comments.destroy', $comment), ['reason' => 'Pierwszy powód.'])->assertRedirect();
        $this->delete(route('comments.destroy', $comment), ['reason' => 'Drugi powód.'])->assertRedirect();
        $this->assertDatabaseCount('notifications', 1);
    }
}
