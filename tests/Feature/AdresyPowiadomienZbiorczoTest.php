<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdresyPowiadomienZbiorczoTest extends TestCase
{
    use RefreshDatabase;

    private function notification(User $viewer, Comment $comment): Notification
    {
        return Notification::create([
            'user_id' => $viewer->id,
            'type' => $comment->parent_id ? Notification::TYPE_REPLY : Notification::TYPE_COMMENT,
            'data' => ['comment_id' => $comment->id, 'url' => '/adres-zapasowy'],
        ]);
    }

    private function comment(User $author, array $attributes): Comment
    {
        return Comment::create($attributes + [
            'author_id' => $author->id,
            'body' => 'Kilka słów o gotowaniu.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    public function test_jeden_odczyt_obsluguje_rozne_tresci_odpowiedzi_i_powtorzone_powiadomienia(): void
    {
        config(['kuking.comments.page_size' => 1]);
        $viewer = $this->user();
        $author = $this->user();
        $subjects = [Post::factory()->create(), Recipe::factory()->create(), CookedEvent::factory()->create()];
        $notifications = [];
        $expected = [];
        foreach ($subjects as $subject) {
            $column = match (true) {
                $subject instanceof Post => 'post_id',
                $subject instanceof Recipe => 'recipe_id',
                default => 'cooked_event_id',
            };
            $this->comment($author, [$column => $subject->id])->forceFill(['created_at' => now()->subHour()])->save();
            $root = $this->comment($author, [$column => $subject->id]);
            $reply = $this->comment($author, [$column => $subject->id, 'parent_id' => $root->id]);
            foreach ([$root, $reply, $reply, $reply] as $comment) {
                $notification = $this->notification($viewer, $comment);
                $notifications[] = $notification;
                $expected[$notification->id] = $subject->url().'?komentarze=2#komentarz-'.$comment->id;
            }
        }

        foreach ([2, 12] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $urls = Notification::destinationUrls(array_slice($notifications, 0, $size), $viewer);
                $queries = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertSame(array_slice($expected, 0, $size, true), $urls);
            $this->assertSame(1, $queries, "Adresy {$size} powiadomień mają wymagać jednego odczytu.");
        }
    }

    public function test_strona_reaguje_na_blokade_w_obie_strony_i_usuniecie_wczesniejszego_korzenia(): void
    {
        config(['kuking.comments.page_size' => 1]);
        $viewer = $this->user();
        $other = $this->user();
        $post = Post::factory()->create();
        $first = $this->comment($other, ['post_id' => $post->id]);
        $root = $this->comment($viewer, ['post_id' => $post->id]);
        // Remis czasu rozstrzyga UUID, tak jak ekran rozmowy.
        foreach ([$first, $root] as $comment) {
            $comment->forceFill(['created_at' => '2026-01-01 12:00:00'])->save();
        }
        $this->assertLessThan($root->id, $first->id);
        $notification = $this->notification($viewer, $root);
        $resolve = fn () => Notification::destinationUrls([$notification], $viewer)[$notification->id];
        $this->assertSame($post->url().'?komentarze=2#komentarz-'.$root->id, $resolve());
        foreach ([[$viewer, $other], [$other, $viewer]] as [$blocker, $blocked]) {
            DB::table('blocks')->insert(['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id, 'created_at' => now()]);
            $this->assertSame($post->url().'#komentarz-'.$root->id, $resolve());
            DB::table('blocks')->where('blocker_id', $blocker->id)->where('blocked_id', $blocked->id)->delete();
            $this->assertSame($post->url().'?komentarze=2#komentarz-'.$root->id, $resolve());
        }
        $first->delete();
        $this->assertSame($post->url().'#komentarz-'.$root->id, $resolve());
        $root->update(['status' => 'hidden']);
        $this->assertSame('/adres-zapasowy', $resolve());
    }

    public function test_braki_celu_i_usuniety_rodzic_zachowuja_adres_zapasowy(): void
    {
        $viewer = $this->user();
        $post = Post::factory()->create();
        $root = $this->comment($viewer, ['post_id' => $post->id]);
        $reply = $this->comment($viewer, ['post_id' => $post->id, 'parent_id' => $root->id]);
        $notification = $this->notification($viewer, $reply);
        $this->assertSame($post->url().'#komentarz-'.$reply->id, Notification::destinationUrls([$notification], $viewer)[$notification->id]);
        $root->delete();
        $this->assertSame('/adres-zapasowy', Notification::destinationUrls([$notification], $viewer)[$notification->id]);
        $root->restore();
        $post->delete();
        $this->assertSame('/adres-zapasowy', Notification::destinationUrls([$notification], $viewer)[$notification->id]);
        $notification->data = [];
        $this->assertSame([$notification->id => null], Notification::destinationUrls([$notification], $viewer));
        $this->assertSame([], Notification::destinationUrls([], $viewer));
    }
}
