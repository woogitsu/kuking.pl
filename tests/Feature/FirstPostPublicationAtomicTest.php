<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\UnansweredContent;
use App\Domain\Posts\Actions\PublishPost;
use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FirstPostPublicationAtomicTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);
    }

    private function host(): User
    {
        $host = $this->moderator();
        config([
            'kuking.community.host_user_id' => $host->getKey(),
            'kuking.community.host_username' => $host->profile->username,
        ]);

        return $host;
    }

    public function test_equal_dates_and_descending_ids_keep_one_alert_and_the_same_panel_carrier(): void
    {
        $host = $this->host();
        $author = User::factory()->create();
        $this->freezeTime();
        $ids = ['ffffffff-ffff-4fff-8fff-ffffffffffff', '00000000-0000-4000-8000-000000000001'];
        Post::creating(function (Post $post) use ($author, &$ids): void {
            if ($post->author_id === $author->id) {
                $post->id = array_shift($ids);
            }
        });
        $first = app(PublishPost::class)->handle($author, 'Pierwsza publikacja.');
        $second = app(PublishPost::class)->handle($author, 'Druga w tej samej chwili.');
        $this->assertTrue($first->published_at->equalTo($second->published_at));
        $alerts = Notification::where('actor_id', $author->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($first->id, $alerts->sole()->data['post_id']);
        $this->assertSame($first->id, app(UnansweredContent::class)->firstPostIds($host, [$author->id])[$author->id]);
    }

    public function test_private_then_public_has_one_alert_and_panel_priority(): void
    {
        $host = $this->host();
        $author = User::factory()->create();
        app(PublishPost::class)->handle($author, 'Prywatna notatka.', visibility: 'private');
        $this->assertSame(0, Notification::where('actor_id', $author->id)->count());
        $first = app(PublishPost::class)->handle($author, 'Pierwszy publiczny.');
        $this->assertSame(1, Notification::where('actor_id', $author->id)->count(), 'Prywatna notatka nie może zabrać alertu pierwszego dostępnego wkładu.');
        $this->assertSame($first->id, Notification::where('actor_id', $author->id)->sole()->data['post_id']);
        $items = $this->actingAs($host)->get(route('admin.unanswered'))->assertOk()->viewData('wpisy');
        $this->assertTrue($items->where('author_id', $author->id)->sole()->toPierwszyWpis);
        Comment::factory()->create(['post_id' => $first->id, 'author_id' => $host->id, 'status' => 'published']);
        $second = app(PublishPost::class)->handle($author, 'Drugi publiczny.');
        $items = $this->get(route('admin.unanswered'))->assertOk()->viewData('wpisy');
        $this->assertSame($second->id, $items->where('author_id', $author->id)->sole()->id);
        $this->assertFalse($items->where('author_id', $author->id)->sole()->toPierwszyWpis);
        $this->assertSame(1, Notification::where('actor_id', $author->id)->count());
    }

    public function test_alert_po_zmianie_nazwy_trafia_do_tego_samego_gospodarza(): void
    {
        $host = $this->host();
        $staraNazwa = $host->profile->username;
        $host->profile->update(['username' => 'gospodarz_po_zmianie']);
        $podszywajacy = $this->user($staraNazwa);
        $author = User::factory()->create();

        $post = app(PublishPost::class)->handle($author, 'Mój pierwszy publiczny wpis.');

        $this->assertSame($post->id, DB::table('first_post_events')->where('author_id', $author->id)->sole()->post_id);
        $this->assertSame(1, Notification::where('user_id', $host->id)->where('actor_id', $author->id)->count());
        $this->assertSame(0, Notification::where('user_id', $podszywajacy->id)->where('actor_id', $author->id)->count());
    }

    public function test_niepoprawny_format_uuid_nie_przerywa_publikacji_i_nie_wysyla_alertu_po_nazwie(): void
    {
        $podszywajacy = $this->user('woogitsu');
        config([
            'kuking.community.host_user_id' => 'to-nie-jest-uuid',
            'kuking.community.host_username' => $podszywajacy->profile->username,
        ]);
        $author = User::factory()->create();

        $post = app(PublishPost::class)->handle($author, 'Pierwszy wpis przy błędnej konfiguracji.');

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'author_id' => $author->id]);
        $this->assertSame(0, Notification::where('user_id', $podszywajacy->id)->where('actor_id', $author->id)->count());
    }

    public function test_followers_first_is_relative_to_the_recipient(): void
    {
        $host = $this->host();
        $otherModerator = $this->moderator();
        $author = User::factory()->create();
        DB::table('follows')->insert(['follower_id' => $host->id, 'followed_id' => $author->id, 'created_at' => now()]);
        $first = app(PublishPost::class)->handle($author, 'Dla obserwujących.', visibility: 'followers');
        $public = app(PublishPost::class)->handle($author, 'Dla wszystkich.');
        $queue = app(UnansweredContent::class);
        $this->assertSame($first->id, $queue->firstPostIds($host, [$author->id])[$author->id]);
        $this->assertSame([], $queue->firstPostIds($otherModerator, [$author->id]));
        $this->assertSame($first->id, Notification::where('actor_id', $author->id)->sole()->data['post_id']);

        $unfollowed = User::factory()->create();
        app(PublishPost::class)->handle($unfollowed, 'Niewidoczny dla gospodarza.', visibility: 'followers');
        $this->assertSame(0, Notification::where('actor_id', $unfollowed->id)->count());
        $visible = app(PublishPost::class)->handle($unfollowed, 'Pierwszy dostępny.');
        $this->assertSame($visible->id, Notification::where('actor_id', $unfollowed->id)->sole()->data['post_id']);
    }

    public function test_deleted_carrier_and_expired_alert_do_not_repeat_the_event(): void
    {
        $host = $this->host();
        $author = User::factory()->create();
        $first = app(PublishPost::class)->handle($author, 'Pierwszy raz.');
        // Usuwamy wyłącznie fixture utworzoną w transakcji tego testu.
        Notification::where('actor_id', $author->id)->delete();
        $first->forceDelete();
        $this->assertNull(DB::table('first_post_events')->where('author_id', $author->id)->sole()->post_id);
        app(PublishPost::class)->handle($author, 'To już nie jest pierwszy wkład.');
        $this->assertSame(0, Notification::where('actor_id', $author->id)->count());
        $this->assertSame([], app(UnansweredContent::class)->firstPostIds($host, [$author->id]));
    }

    public function test_public_without_host_consumes_event_but_followers_does_not(): void
    {
        config(['kuking.community.host_username' => '']);
        $publicAuthor = User::factory()->create();
        $followersAuthor = User::factory()->create();
        $first = app(PublishPost::class)->handle($publicAuthor, 'Pierwszy publiczny bez gospodarza.');
        app(PublishPost::class)->handle($followersAuthor, 'Bez odbiorcy.', visibility: 'followers');
        $this->assertSame($first->id, DB::table('first_post_events')->where('author_id', $publicAuthor->id)->value('post_id'));
        $this->assertFalse(DB::table('first_post_events')->where('author_id', $followersAuthor->id)->exists());
        $host = $this->host();
        app(PublishPost::class)->handle($publicAuthor, 'Nie witaj ponownie.');
        $visible = app(PublishPost::class)->handle($followersAuthor, 'Teraz publicznie.');
        $this->assertSame(0, Notification::where('actor_id', $publicAuthor->id)->count());
        $this->assertSame($visible->id, Notification::where('actor_id', $followersAuthor->id)->sole()->data['post_id']);
        $this->assertSame($first->id, app(UnansweredContent::class)->firstPostIds($host, [$publicAuthor->id])[$publicAuthor->id]);
    }

    public function test_changing_host_does_not_repeat_the_event(): void
    {
        $this->host();
        $author = User::factory()->create();
        $first = app(PublishPost::class)->handle($author, 'Pierwszy wkład.');
        $nextHost = $this->host();
        app(PublishPost::class)->handle($author, 'Nowy gospodarz, ten sam autor.');
        $this->assertSame(1, Notification::where('actor_id', $author->id)->count());
        $this->assertSame(0, Notification::where('user_id', $nextHost->id)->where('actor_id', $author->id)->count());
        $this->assertSame($first->id, app(UnansweredContent::class)->firstPostIds($nextHost, [$author->id])[$author->id]);
    }

    public function test_blocked_public_post_does_not_consume_first_available_event(): void
    {
        $host = $this->host();
        $author = User::factory()->create();
        DB::table('blocks')->insert(['blocker_id' => $host->id, 'blocked_id' => $author->id, 'created_at' => now()]);
        app(PublishPost::class)->handle($author, 'Niedostępny gospodarzowi.');
        $this->assertFalse(DB::table('first_post_events')->where('author_id', $author->id)->exists());
        $this->assertSame(0, Notification::where('actor_id', $author->id)->count());
        DB::table('blocks')->where('blocker_id', $host->id)->where('blocked_id', $author->id)->delete();
        $first = app(PublishPost::class)->handle($author, 'Pierwszy dostępny po odblokowaniu.');
        $this->assertSame($first->id, Notification::where('actor_id', $author->id)->sole()->data['post_id']);
    }

    public static function failures(): array
    {
        return [['audit'], ['alert'], ['enqueue']];
    }

    #[DataProvider('failures')]
    public function test_failure_rolls_back_all_effects_and_retry_finishes_once(string $stage): void
    {
        $this->host();
        $author = User::factory()->create();
        $key = (string) Str::uuid();
        $jobsBefore = DB::table('jobs')->count();
        $active = true;
        $fault = function () use (&$active, $stage): void {
            if ($active) {
                throw new RuntimeException('awaria-'.$stage);
            }
        };
        if ($stage === 'audit') {
            AuditLogEntry::creating(fn () => $fault());
        } elseif ($stage === 'alert') {
            Notification::creating(fn () => $fault());
        } else {
            DB::connection()->beforeExecuting(function ($query) use ($fault): void {
                if (str_starts_with($query, 'insert into "jobs"')) {
                    $fault();
                }
            });
        }
        $caught = null;
        try {
            app(PublishPost::class)->handle($author, 'Rosół po awarii.', kluczWyslania: $key);
        } catch (RuntimeException $error) {
            $caught = $error;
        } finally {
            $active = false;
        }
        $this->assertNotNull($caught, 'Wstrzyknięta awaria musi wystąpić.');
        $this->assertSame('awaria-'.$stage, $caught->getMessage());
        $this->assertSame(0, Post::where('author_id', $author->id)->count(), 'Awaria finalizacji nie może zostawić wpisu.');
        $this->assertSame(0, AuditLogEntry::where('actor_id', $author->id)->count());
        $this->assertSame(0, Notification::where('actor_id', $author->id)->count());
        $this->assertFalse(DB::table('first_post_events')->where('author_id', $author->id)->exists());
        $this->assertSame($jobsBefore, DB::table('jobs')->count());
        $first = app(PublishPost::class)->handle($author, 'Rosół po awarii.', kluczWyslania: $key);
        $retry = app(PublishPost::class)->handle($author, 'Rosół po awarii.', kluczWyslania: $key);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, Post::where('author_id', $author->id)->count());
        $this->assertSame(1, AuditLogEntry::where('actor_id', $author->id)->where('action', 'post.published')->count());
        $this->assertSame(1, Notification::where('actor_id', $author->id)->count());
        $this->assertSame($jobsBefore + 1, DB::table('jobs')->count());
    }

    public function test_standard_database_worker_can_pop_the_real_job(): void
    {
        $author = User::factory()->create();
        config(['kuking.community.host_username' => '', 'queue.connections.database.connection' => config('database.default')]);
        $jobsBefore = DB::table('jobs')->where('queue', 'low')->count();
        $post = app(PublishPost::class)->handle($author, 'Nie wykonuj analizy w żądaniu.');
        // Pop rezerwuje, nie wykonuje ani nie usuwa istniejących zadań.
        // Całą rezerwację cofa transakcja tego testu.
        $command = null;
        for ($attempt = 0; $attempt <= $jobsBefore; $attempt++) {
            $job = Queue::connection('database')->pop('low');
            $this->assertNotNull($job);
            $payload = $job->payload();
            $command = unserialize($payload['data']['command'], ['allowed_classes' => [PrzeanalizujTresc::class]]);
            if ($command instanceof PrzeanalizujTresc && $command->id === $post->id) {
                break;
            }
        }
        $this->assertNotNull($job);
        $this->assertInstanceOf(PrzeanalizujTresc::class, $command);
        $this->assertSame($post->id, $command->id);
        $this->assertSame('post', $command->typ);
        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_incompatible_worker_database_is_rejected_before_publication(): void
    {
        $author = User::factory()->create();
        config(['database.connections.foreign_queue' => [...config('database.connections.pgsql'), 'database' => 'not_this_database']]);
        config(['queue.connections.database.connection' => 'foreign_queue']);
        $caught = null;
        try {
            app(PublishPost::class)->handle($author, 'Nie zapisuj częściowo.');
        } catch (\LogicException $error) {
            $caught = $error;
        }
        $this->assertNotNull($caught);
        $this->assertStringContainsString('DB_QUEUE_CONNECTION', $caught->getMessage());
        $this->assertSame(0, Post::where('author_id', $author->id)->count());
        $this->assertSame(0, AuditLogEntry::where('actor_id', $author->id)->count());
    }

    public function test_even_an_alias_of_the_same_database_is_rejected_before_writes(): void
    {
        $author = User::factory()->create();
        config(['database.connections.other_alias' => config('database.connections.pgsql'),
            'queue.connections.database.connection' => 'other_alias']);
        try {
            app(PublishPost::class)->handle($author, 'Dwa połączenia to nie jedna transakcja.');
            $this->fail('Odrębny obiekt połączenia musi zostać odrzucony.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('DB_QUEUE_CONNECTION', $error->getMessage());
        }
        $this->assertSame(0, Post::where('author_id', $author->id)->count());
    }
}
