<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FirstPostEventMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_21_100900_create_first_post_events.php');
    }

    public function test_empty_rollback_and_reapply_work(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('first_post_events'));
        $this->migration()->up();
        $this->assertSame(0, DB::table('first_post_events')->count());
    }

    public function test_backfill_prefers_alert_and_does_not_send_historical_alerts(): void
    {
        $this->migration()->down();
        $host = $this->moderator();
        config(['kuking.community.host_username' => $host->profile->username]);
        $author = User::factory()->create();
        $older = Post::factory()->create(['author_id' => $author->id, 'published_at' => now()->subDays(2)]);
        $carrier = Post::factory()->create(['author_id' => $author->id, 'published_at' => now()->subDay()]);
        Notification::create(['user_id' => $host->id, 'actor_id' => $author->id, 'type' => Notification::TYPE_FIRST_POST,
            'data' => ['post_id' => $carrier->id]]);
        $this->migration()->up();
        $this->assertSame($carrier->id, DB::table('first_post_events')->where('author_id', $author->id)->value('post_id'));
        $this->assertNotSame($older->id, $carrier->id);
        $this->assertSame(1, Notification::count());
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame($carrier->id, DB::table('first_post_events')->where('author_id', $author->id)->value('post_id'));
    }

    public function test_backfill_includes_soft_deleted_public_but_not_private_or_unseen_followers(): void
    {
        $this->migration()->down();
        $host = $this->moderator();
        $staraNazwa = $host->profile->username;
        config([
            'kuking.community.host_user_id' => $host->getKey(),
            'kuking.community.host_username' => $staraNazwa,
        ]);
        $host->profile->update(['username' => 'gospodarz_po_zmianie']);
        $this->user($staraNazwa);
        $public = User::factory()->create();
        $private = User::factory()->create();
        $unseen = User::factory()->create();
        $followed = User::factory()->create();
        $old = Post::factory()->create(['author_id' => $public->id, 'visibility' => 'public', 'deleted_at' => now()]);
        Post::factory()->create(['author_id' => $private->id, 'visibility' => 'private']);
        Post::factory()->create(['author_id' => $unseen->id, 'visibility' => 'followers']);
        $followers = Post::factory()->create(['author_id' => $followed->id, 'visibility' => 'followers']);
        DB::table('follows')->insert(['follower_id' => $host->id, 'followed_id' => $followed->id, 'created_at' => now()]);
        $this->migration()->up();
        $this->assertSame($old->id, DB::table('first_post_events')->where('author_id', $public->id)->value('post_id'));
        $this->assertFalse(DB::table('first_post_events')->whereIn('author_id', [$private->id, $unseen->id])->exists());
        $this->assertSame($followers->id, DB::table('first_post_events')->where('author_id', $followed->id)->value('post_id'));
        $this->assertSame(0, Notification::count());
        app(PublishPost::class)->handle($public, 'Po usuniętym pierwszym.');
        $this->assertSame(0, Notification::where('actor_id', $public->id)->count());
    }

    public function test_alert_survives_missing_post_and_backfills_null_without_guessing(): void
    {
        $this->migration()->down();
        $host = $this->moderator();
        $author = User::factory()->create();
        Notification::create(['user_id' => $host->id, 'actor_id' => $author->id, 'type' => Notification::TYPE_FIRST_POST,
            'data' => ['post_id' => '10000000-0000-4000-8000-000000000001']]);
        $this->migration()->up();
        $this->assertNull(DB::table('first_post_events')->where('author_id', $author->id)->sole()->post_id);
        $this->assertSame(1, Notification::count());
    }

    public function test_rollback_refuses_lost_carrier_and_keeps_the_marker(): void
    {
        config(['kuking.community.host_username' => '']);
        $author = User::factory()->create();
        $post = app(PublishPost::class)->handle($author, 'Nie zapomnij zdarzenia.');
        $post->forceDelete();
        try {
            $this->migration()->down();
            $this->fail('Rollback musi odmówić utraty zdarzenia.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Nie można cofnąć', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('first_post_events'));
        $this->assertNull(DB::table('first_post_events')->where('author_id', $author->id)->sole()->post_id);
    }

    public function test_rollback_refuses_replacing_carrier_with_another_post(): void
    {
        config(['kuking.community.host_username' => '']);
        $author = User::factory()->create();
        $first = app(PublishPost::class)->handle($author, 'Utrwalony pierwszy.');
        Post::factory()->create(['author_id' => $author->id, 'published_at' => now()->subDay()]);
        try {
            $this->migration()->down();
            $this->fail('Rollback nie może podmienić nośnika.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Nie można cofnąć', $error->getMessage());
        }
        $this->assertSame($first->id, DB::table('first_post_events')->where('author_id', $author->id)->value('post_id'));
    }

    public function test_database_rejects_a_carrier_of_another_author(): void
    {
        $author = User::factory()->create();
        $other = Post::factory()->create();
        try {
            DB::transaction(fn () => DB::table('first_post_events')->insert(['author_id' => $author->id, 'post_id' => $other->id]));
            $this->fail('Klucz obcy musi odrzucić cudzy nośnik.');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
        $this->assertFalse(DB::table('first_post_events')->where('author_id', $author->id)->exists());
    }
}
