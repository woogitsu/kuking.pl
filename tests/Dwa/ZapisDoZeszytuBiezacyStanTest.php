<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regresja #1022: zapis do zeszytu po utracie dostępu do celu.
 *
 * Dwa przeploty dla każdej zmiany stanu:
 *  • „zmiana” — zapis zatrzymany PO sprawdzeniu, jakie robi kontroler;
 *    zmiana zatwierdza się, zapis rusza dalej i MA odmówić bez mutacji;
 *  • „zapis” — zapis zatrzymany WEWNĄTRZ transakcji, pod zamkiem treści
 *    albo zeszytu; zmiana MA na niego poczekać (kontrola, że zamek jest
 *    wspólną granicą) i przejść bez zakleszczenia, a zapis się udaje.
 */
#[Group('dwa-polaczenia')]
final class ZapisDoZeszytuBiezacyStanTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $workers = [];

    /** @param array<string, mixed> $atrybuty */
    protected function konto(array $atrybuty = []): User
    {
        // Faker gwarantuje unikalność tylko w jednym procesie, a baza wyścigów
        // zachowuje fixture poprzednich przebiegów.
        return parent::konto(array_merge(['email' => 'zeszyt-'.bin2hex(random_bytes(12)).'@example.invalid'], $atrybuty));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->zabij();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        // Wersje z edycji przepisu mają RESTRICT na `editor_id`.
        DB::table('recipe_versions')->whereIn('editor_id', $this->konta)->delete();
        parent::tearDown();
    }

    public static function races(): iterable
    {
        foreach (['recipe', 'post'] as $type) {
            foreach (['private', 'hide', 'remove', 'block_forward', 'block_reverse', 'delete_collection', 'ban', 'close', 'suspend_public'] as $operation) {
                foreach (['zmiana', 'zapis'] as $first) {
                    yield "$type/$operation/$first" => [$type, $operation, $first === 'zapis'];
                }
            }
        }
    }

    #[DataProvider('races')]
    public function test_zapis_rozstrzyga_sie_na_swiezym_stanie(string $type, string $operation, bool $saveFirst): void
    {
        $saver = $this->konto();
        $author = $this->konto();
        $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
        $subject = $type === 'recipe'
            ? Recipe::factory()->for($author, 'author')->create($attributes)
            : Post::factory()->for($author, 'author')->create($attributes);
        $column = $type === 'recipe' ? 'recipe_id' : 'post_id';

        $collection = null;
        if ($operation === 'delete_collection') {
            $collection = Collection::create(['owner_id' => $saver->id, 'name' => 'Obiady '.bin2hex(random_bytes(3)), 'visibility' => 'private']);
        } elseif ($operation === 'suspend_public') {
            $collection = Collection::create(['owner_id' => $saver->id, 'name' => 'Publiczny '.bin2hex(random_bytes(3)), 'visibility' => 'public']);
        }

        $name = 'zeszyt-race-'.bin2hex(random_bytes(5));
        $save = ['name' => $name, 'type' => $type, 'subject' => $subject->id, 'actor' => $saver->id];
        if ($collection !== null) {
            $save['collection'] = $collection->id;
        }
        $change = ['name' => $name.'-change', 'type' => $type, 'subject' => $subject->id];
        [$scenario, $change] = $this->zmiana($operation, $change, $saver, $author, $subject, $collection);

        if ($saveFirst) {
            // Zatrzymanie pod zamkiem, który dana zmiana musi uszanować.
            $save['after_sql'] = in_array($operation, ['delete_collection', 'suspend_public'], true)
                ? ['from "collections"', 'for no key update']
                : ['from "'.$subject->getTable().'"', 'for no key update'];
        } else {
            $save['pause'] = 'po_sprawdzeniu';
        }

        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$name]);
        $saving = $this->worker('save', $save);
        $savingPid = $this->waitFor($name, $barrier);
        $other = $this->worker($scenario, $change);

        if ($saveFirst) {
            // Kontrola wspólnej granicy: zmiana naprawdę czeka na zapis.
            $changePid = $this->waitFor($change['name'], null);
            $this->assertNotSame($savingPid, $changePid);
            $this->zwolnijBariere($barrier);
            $saved = $saving->wynik(12);
            $changed = $other->wynik(12);
        } else {
            $changed = $other->wynik(12);
            $this->zwolnijBariere($barrier);
            $saved = $saving->wynik(12);
        }

        $this->assertTrue($changed['ok'], 'Zmiana stanu padła: '.$changed['komunikat']);
        $this->assertNotSame('40P01', $saved['sqlstate'], $saved['komunikat']);
        $this->assertNotSame('55P03', $saved['sqlstate'], $saved['komunikat']);
        $this->assertSame($saveFirst, $saved['ok'], (string) $saved['komunikat']);

        $notifications = Notification::query()->where('user_id', $author->id)->where('type', Notification::TYPE_SAVED)->count();

        if ($saveFirst) {
            // Zapis wygrał uczciwie; późniejsza zmiana nie czyści historii (#773),
            // wyjątek: usunięcie zeszytu zabiera jego wiersze kaskadą.
            $expected = $operation === 'delete_collection' ? 0 : 1;
            $this->assertSame($expected, DB::table('collection_items')->where($column, $subject->id)->count());
            $this->assertSame($type === 'recipe' ? 1 : 0, $notifications);

            return;
        }

        $expectedException = in_array($operation, ['ban', 'close', 'suspend_public'], true)
            ? AuthorizationException::class
            : BladDlaCzlowieka::class;
        $this->assertSame($expectedException, $saved['wyjatek'], (string) $saved['komunikat']);
        if ($expectedException === BladDlaCzlowieka::class) {
            $this->assertContains($saved['komunikat'], [ZamekZapisuDoZeszytu::NIEDOSTEPNE, ZamekZapisuDoZeszytu::BRAK_ZESZYTU]);
        }
        $this->assertSame(0, DB::table('collection_items')->where($column, $subject->id)->count());
        $this->assertSame(0, $notifications);
        // Usunięty zeszyt nie zamienia się po cichu w domyślny.
        $this->assertSame(0, DB::table('collections')->where('owner_id', $saver->id)->where('is_default', true)->count());
    }

    public function test_kontrola_dodatnia_niezmieniona_tresc_zapisuje_sie_raz(): void
    {
        foreach (['recipe', 'post'] as $type) {
            $saver = $this->konto();
            $author = $this->konto();
            $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
            $subject = $type === 'recipe'
                ? Recipe::factory()->for($author, 'author')->create($attributes)
                : Post::factory()->for($author, 'author')->create($attributes);
            $name = 'zeszyt-plus-'.bin2hex(random_bytes(5));

            $barrier = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$name]);
            $saving = $this->worker('save', ['name' => $name, 'type' => $type, 'subject' => $subject->id, 'actor' => $saver->id, 'pause' => 'po_sprawdzeniu']);
            $this->waitFor($name, $barrier);
            $this->zwolnijBariere($barrier);
            $saved = $saving->wynik(12);

            $this->assertTrue($saved['ok'], (string) $saved['komunikat']);
            $column = $type === 'recipe' ? 'recipe_id' : 'post_id';
            $this->assertSame(1, DB::table('collection_items')->where($column, $subject->id)->count());
        }
    }

    /**
     * @param  array<string, string>  $change
     * @return array{0: string, 1: array<string, string>}
     */
    private function zmiana(string $operation, array $change, User $saver, User $author, Post|Recipe $subject, ?Collection $collection): array
    {
        return match ($operation) {
            'private' => ['private', $change + ['actor' => $author->id]],
            'block_forward' => ['block', $change + ['actor' => $saver->id, 'other' => $author->id]],
            'block_reverse' => ['block', $change + ['actor' => $author->id, 'other' => $saver->id]],
            'delete_collection' => ['delete_collection', $change + ['actor' => $saver->id, 'collection' => $collection->id]],
            'ban' => ['status', $change + ['actor' => $saver->id, 'transition' => 'zbanuj']],
            'close' => ['status', $change + ['actor' => $saver->id, 'transition' => 'usun']],
            'suspend_public' => ['status', $change + ['actor' => $saver->id, 'transition' => 'zawies']],
            'hide', 'remove' => (function () use ($operation, $change, $saver, $subject): array {
                // Moderator zostaje jako fixture: dziennik moderacji ma RESTRICT.
                $moderator = User::factory()->create(['email' => 'moderator-'.bin2hex(random_bytes(12)).'@example.invalid']);
                $moderator->forceFill(['role' => 'moderator'])->save();
                $report = Report::create([
                    'reporter_id' => $saver->id, 'source' => Report::SOURCE_COMMUNITY,
                    'target_type' => $subject instanceof Recipe ? 'recipe' : 'post',
                    'target_id' => $subject->id, 'reason' => 'spam', 'status' => 'open',
                ]);

                return ['moderate', $change + ['actor' => $moderator->id, 'report' => $report->id, 'decision' => $operation]];
            })(),
        };
    }

    /** @param array<string, mixed> $args */
    private function worker(string $scenario, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/zeszyt.php', $scenario, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }

    /** Czeka, aż proces o tej nazwie stoi w kolejce po blokadę (i czyją). */
    private function waitFor(string $name, ?PDO $barrier): int
    {
        $ownerPid = $barrier !== null ? (int) $barrier->query('SELECT pg_backend_pid()')->fetchColumn() : null;
        $query = $this->obserwator->prepare("SELECT pid, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        $deadline = microtime(true) + 6;
        do {
            $query->execute([$name]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($ownerPid !== null) {
                    $this->assertStringContainsString((string) $ownerPid, $row['blockers']);
                }

                return (int) $row['pid'];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Nie potwierdzono rzeczywistej bariery procesu '.$name);
    }
}
