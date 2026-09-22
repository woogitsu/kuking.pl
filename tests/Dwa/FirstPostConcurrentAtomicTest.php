<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\UnansweredContent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/** Rzeczywiste commity i obserwowana blokada autora przed rozstrzygnięciem pierwszeństwa. */
#[Group('dwa-polaczenia')]
class FirstPostConcurrentAtomicTest extends TestDwochPolaczen
{
    public function test_author_serialization_keeps_one_first_alert(): void
    {
        $host = $this->konto();
        $author = $this->konto();
        $keys = [(string) Str::uuid(), (string) Str::uuid()];
        $this->assertNotSame(...$keys);
        $barrier = random_int(30000000, 40000000);
        DB::select('select pg_advisory_lock(?)', [$barrier]);
        $processes = [];
        $pids = [];
        $serialized = false;
        try {
            foreach ($keys as $index => $key) {
                $process = new Process([PHP_BINARY, 'tests/Dwa/bin/publishAtomic1009.php', $author->id,
                    $host->id, $key, $index === 0 ? (string) $barrier : '0'],
                    base_path(), ['APP_BASE_PATH' => base_path(), 'DB_DATABASE' => $this->baza], timeout: 30);
                $process->start();
                $processes[] = $process;
                $deadline = microtime(true) + 10;
                do {
                    if (preg_match('/PID=(\d+)/', $process->getOutput(), $match)) {
                        $pids[$index] = (int) $match[1];
                        $waiting = DB::table('pg_locks')->where('pid', $pids[$index])->where('granted', false)->exists();
                        if ($waiting || ! $process->isRunning()) {
                            break;
                        }
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertArrayHasKey($index, $pids, $process->getErrorOutput());
                if ($index === 0) {
                    $this->assertTrue(DB::table('pg_locks')->where('pid', $pids[0])->where('locktype', 'advisory')->where('granted', false)->exists());
                    $this->assertSame(0, Post::where('author_id', $author->id)->count(), 'Pierwszy wpis nie może być zatwierdzony przed finalizacją.');
                    // Pomiar kompatybilności NO KEY UPDATE z FK KEY SHARE, gdy autor jest nadal zablokowany.
                    $this->assertSame($author->id, User::whereKey($author->id)->lock('FOR KEY SHARE')->sole()->id);
                } else {
                    $serialized = (bool) DB::selectOne('select ? = ANY(pg_blocking_pids(?)) as blocked', [$pids[0], $pids[1]])->blocked;
                }
            }
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$barrier]);
            foreach ($processes as $process) {
                try {
                    $process->wait();
                } finally {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
            }
        }
        $this->assertCount(2, array_unique($pids));
        foreach ($processes as $process) {
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        }
        $posts = Post::where('author_id', $author->id)->whereIn('klucz_wyslania', $keys)->pluck('id');
        $this->assertCount(2, $posts);
        $this->assertSame(2, DB::table('audit_log')->where('actor_id', $author->id)->where('action', 'post.published')->count());
        $jobs = DB::table('jobs')->where('queue', 'low')->get()->filter(function ($row) use ($posts): bool {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return collect($posts)->contains(fn ($id) => str_contains($payload['data']['command'], $id));
        });
        $this->assertCount(2, $jobs);
        $alerts = Notification::where('user_id', $host->id)->where('actor_id', $author->id)->where('type', Notification::TYPE_FIRST_POST)->get();
        fwrite(STDERR, 'ATOMIC1009 '.json_encode(['pids' => $pids, 'serialized' => $serialized, 'posts' => 2, 'jobs' => $jobs->count(), 'alerts' => $alerts->count()]).PHP_EOL);
        $this->assertCount(1, $alerts, 'Równoległe publikacje muszą dać dokładnie jeden alert.');
        $carrier = DB::table('first_post_events')->where('author_id', $author->id)->sole()->post_id;
        $this->assertSame($carrier, $alerts->sole()->data['post_id']);
        $this->assertSame($carrier, app(UnansweredContent::class)->firstPostIds($host, [$author->id])[$author->id]);
        $this->assertTrue($serialized, 'Druga publikacja musi czekać na finalizację pierwszej.');
        $this->assertTrue($posts->contains($alerts->sole()->data['post_id']));
    }
}
