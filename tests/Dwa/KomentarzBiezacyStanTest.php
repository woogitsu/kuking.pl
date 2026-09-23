<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Comments\Actions\PublishComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('dwa-polaczenia')]
final class KomentarzBiezacyStanTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $workers = [];

    /** @param array<string, mixed> $atrybuty */
    protected function konto(array $atrybuty = []): User
    {
        // Faker gwarantuje unikalność tylko w jednym procesie; baza wyścigów
        // zachowuje fixture poprzednich przebiegów i dziennik moderacji.
        return parent::konto(array_merge(['email' => 'race-'.bin2hex(random_bytes(12)).'@example.invalid'], $atrybuty));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->zabij();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        // Wyłącznie wersje fixture tych kont; RESTRICT editora poprzedza
        // istniejącą sprzątaczkę kont. Żadnego wyłączania zabezpieczeń.
        DB::table('recipe_versions')->whereIn('editor_id', $this->konta)->delete();
        parent::tearDown();
    }

    public static function races(): iterable
    {
        foreach (['post', 'recipe', 'cooked'] as $type) {
            $operations = ['block_forward', 'block_reverse', 'unfollow', 'edit', 'remove', 'parent_remove', 'parent_hide', 'parent_block_forward', 'parent_block_reverse', 'root_hide', 'root_remove', 'root_block_forward', 'root_block_reverse'];
            if ($type === 'cooked') {
                $operations = array_merge($operations, ['recipe_block_forward', 'recipe_block_reverse']);
            }
            foreach ($operations as $operation) {
                foreach ([false, true] as $publishFirst) {
                    yield "$type/$operation/".($publishFirst ? 'publikacja' : 'zmiana') => [$type, $operation, $publishFirst];
                }
            }
            if ($type !== 'cooked') {
                foreach ([false, true] as $publishFirst) {
                    yield "$type/hide/".($publishFirst ? 'publikacja' : 'zmiana') => [$type, 'hide', $publishFirst];
                }
            }
        }
    }

    #[DataProvider('races')]
    public function test_rzeczywiste_akcje_maja_jedno_rozstrzygniecie(string $type, string $operation, bool $publishFirst): void
    {
        $owner = $this->konto();
        $writer = $this->konto();
        $recipeAuthor = $type === 'cooked' ? $this->konto() : $owner;
        $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
        $recipe = $type !== 'post' ? Recipe::factory()->for($recipeAuthor, 'author')->create($attributes) : null;
        $subject = match ($type) {
            'post' => Post::factory()->for($owner, 'author')->create($attributes),
            'recipe' => $recipe,
            'cooked' => CookedEvent::factory()->for($owner, 'user')->for($recipe)->create(),
        };
        $parentAuthor = $this->konto();
        $parent = app(PublishComment::class)->handle($parentAuthor, $subject, 'Rodzic wyścigu.');
        $root = $parent;
        if (str_starts_with($operation, 'root_')) {
            $root = app(PublishComment::class)->handle($this->konto(), $subject, 'Osobny korzeń wyścigu.');
            $parent = app(PublishComment::class)->handle($parentAuthor, $subject, 'Wybrana odpowiedź.', $root);
        }
        $positive = app(PublishComment::class)->handle($writer, $subject, 'Dodatnia kontrola przed wyścigiem.', $parent);
        $this->assertSame(2, Notification::query()->where('data->comment_id', $positive->id)->count());
        if ($operation === 'parent_remove') {
            // Usuwamy tylko kontrolną odpowiedź fixture, aby sprawdzać zmianę
            // decyzji z delete na placeholder przy zwycięstwie publikacji.
            $positive->delete();
        }
        if ($operation === 'unfollow') {
            ($recipe ?? $subject)->update(['visibility' => 'followers']);
            $writer->following()->attach($recipeAuthor->id);
        }

        $common = ['type' => $type, 'subject' => $subject->id, 'parent' => $parent->id];
        $publish = $common + ['actor' => $writer->id, 'body' => 'Odpowiedź w wyścigu.'];
        $change = $common + ['actor' => $owner->id];
        $scenario = match ($operation) {
            'block_forward', 'block_reverse', 'parent_block_forward', 'parent_block_reverse', 'recipe_block_forward', 'recipe_block_reverse', 'root_block_forward', 'root_block_reverse' => 'block',
            'unfollow' => 'unfollow', 'edit' => $type === 'post' ? 'edit_post' : 'edit_recipe',
            'parent_remove' => 'delete_parent', default => 'moderate',
        };
        if (str_contains($operation, 'block_')) {
            $blockedPerson = match (true) {
                str_starts_with($operation, 'parent_') => $parentAuthor,
                str_starts_with($operation, 'root_') => $root->author,
                str_starts_with($operation, 'recipe_') => $recipeAuthor,
                default => $owner,
            };
            $forward = str_ends_with($operation, '_forward');
            $change['actor'] = $forward ? $writer->id : $blockedPerson->id;
            $change['other'] = $forward ? $blockedPerson->id : $writer->id;
        } elseif ($operation === 'unfollow') {
            $change['actor'] = $writer->id;
            $change['other'] = $recipeAuthor->id;
        } elseif ($operation === 'edit') {
            $change['actor'] = $recipeAuthor->id;
            if ($recipe !== null) {
                $change['recipe'] = $recipe->id;
            }
        } elseif ($operation === 'parent_remove') {
            $change['actor'] = $parentAuthor->id;
        } else {
            // Moderator zostaje jako fixture: log moderacji ma RESTRICT.
            $moderator = User::factory()->create(['email' => 'moderator-'.bin2hex(random_bytes(12)).'@example.invalid']);
            $moderator->forceFill(['role' => 'moderator'])->save();
            $report = Report::create([
                'reporter_id' => $writer->id, 'source' => Report::SOURCE_COMMUNITY,
                'target_type' => $operation === 'parent_hide' || str_starts_with($operation, 'root_') ? 'comment' : ($type === 'cooked' ? 'cooked_event' : $type),
                'target_id' => $operation === 'parent_hide' ? $parent->id : (str_starts_with($operation, 'root_') ? $root->id : $subject->id),
                'reason' => 'spam', 'status' => 'open',
            ]);
            $change['actor'] = $moderator->id;
            $change['report'] = $report->id;
            $change['decision'] = str_ends_with($operation, 'remove') ? 'remove' : 'hide';
        }

        $name = 'comment-race-'.bin2hex(random_bytes(5));
        $publish['name'] = $name;
        $change['name'] = $name.'-change';
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$name]);
        if ($publishFirst) {
            $publish['after_sql'] = 'select pg_advisory_xact_lock(8301';
        } else {
            $publish['pause'] = 'loaded';
        }
        $first = $this->worker('publish', $publish);
        $publicationPid = $this->waitFor($name, $barrier, 'pg_advisory');
        $other = $this->worker($scenario, $change);
        if ($publishFirst) {
            $changePid = $this->waitFor($change['name'], null, null);
            $this->assertNotSame($publicationPid, $changePid);
            $this->zwolnijBariere($barrier);
            $published = $first->wynik(12);
            $changed = $other->wynik(12);
        } else {
            $changed = $other->wynik(12);
            $this->zwolnijBariere($barrier);
            $published = $first->wynik(12);
        }
        $this->assertTrue($changed['ok'], $changed['komunikat']);
        $this->assertNotSame('40P01', $published['sqlstate']);
        $this->assertNotSame('55P03', $published['sqlstate']);
        $this->assertSame($publishFirst, $published['ok'], $published['komunikat']);
        if ($publishFirst) {
            // Twarde usunięcie wykonania później legalnie usuwa komentarze
            // kaskadą; powiadomienia nadal dowodzą wygranej publikacji.
            $this->assertSame(2, Notification::query()->where('data->comment_id', $published['wartosc'])->count());
            if (! ($type === 'cooked' && $operation === 'remove')) {
                $this->assertTrue(Comment::withTrashed()->whereKey($published['wartosc'])->exists());
            }
            if ($operation === 'parent_remove') {
                $this->assertNotNull($parent->fresh(), 'Zapisana odpowiedź utraciła rodzica przez nieaktualną decyzję usunięcia.');
                $this->assertNull($parent->fresh()->deleted_at, 'Rodzic nowej odpowiedzi został usunięty zamiast zachować placeholder.');
                $this->assertSame('Komentarz usunięty.', $parent->fresh()->body);
                $this->assertNotNull($parent->fresh()->body_removed_at);
            }
            // Rzeczywisty późniejszy odczyt, nie sam wiersz w tabeli.
            // Placeholder jest legalnie widoczny; inne zmiany odcinają treść.
            $response = $this->actingAs($writer->fresh())->get($subject->url());
            if ($operation === 'parent_remove') {
                $response->assertOk()->assertSee('Komentarz usunięty.')->assertSee($publish['body']);
            } else {
                $this->assertContains($response->status(), [200, 403, 404]);
                $response->assertDontSee($publish['body']);
            }
        } else {
            $this->assertSame(BladDlaCzlowieka::class, $published['wyjatek']);
            $this->assertFalse(Comment::withTrashed()->where('body', $publish['body'])->where('author_id', $writer->id)->exists());
            $this->assertSame(0, Notification::query()->where('actor_id', $writer->id)->where('data->excerpt', $publish['body'])->count());
        }
    }

    /** @param array<string, string> $args */
    private function worker(string $scenario, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/komentarz.php', $scenario, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }

    public static function types(): iterable
    {
        foreach (['post', 'recipe', 'cooked'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('types')]
    public function test_moderacja_trzyma_cel_a_komentujacy_jest_zglaszajacym(string $type): void
    {
        $writer = $this->konto();
        $owner = $this->konto();
        $this->assertLessThan($owner->id, $writer->id, 'Zgłaszający musi być pierwszym zamkiem konta.');
        $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
        $subject = match ($type) {
            'post' => Post::factory()->for($owner, 'author')->create($attributes),
            'recipe' => Recipe::factory()->for($owner, 'author')->create($attributes),
            'cooked' => CookedEvent::factory()->for($owner, 'user')->for(Recipe::factory()->for($owner, 'author')->create($attributes))->create(),
        };
        $positive = app(PublishComment::class)->handle($writer, $subject, 'Kontrola przed moderacją.');
        $this->assertSame(1, Notification::query()->where('data->comment_id', $positive->id)->count());
        $moderator = User::factory()->create(['email' => 'moderator-'.bin2hex(random_bytes(12)).'@example.invalid']);
        $moderator->forceFill(['role' => 'moderator'])->save();
        $report = Report::create([
            'reporter_id' => $writer->id, 'source' => Report::SOURCE_COMMUNITY,
            'target_type' => $type === 'cooked' ? 'cooked_event' : $type,
            'target_id' => $subject->id, 'reason' => 'spam', 'status' => 'open',
        ]);
        $name = 'moderation-fk-'.bin2hex(random_bytes(5));
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$name]);
        $moderation = $this->worker('moderate', [
            'name' => $name, 'type' => $type, 'subject' => $subject->id, 'actor' => $moderator->id,
            'report' => $report->id, 'decision' => $type === 'cooked' ? 'remove' : 'hide',
            'after_sql' => $type === 'cooked' ? 'delete from "cooked_events"' : 'update "'.$subject->getTable().'"',
        ]);
        $moderationPid = $this->waitFor($name, $barrier, 'pg_advisory');
        $publication = $this->worker('publish', [
            'name' => $name.'-publication', 'type' => $type, 'subject' => $subject->id,
            'actor' => $writer->id, 'body' => 'Odmowa po moderacji.',
        ]);
        // Przy złym FOR UPDATE czekanie może zacząć się już na wczesnym FK
        // właściciela. Nadal puszczamy pełną moderację: wynik ma wykazać
        // rzeczywisty deadlock z późnym FK zgłaszającego, nie brak bariery.
        $publicationPid = $this->waitFor($name.'-publication', null, null);
        $this->assertNotSame($moderationPid, $publicationPid);
        $this->zwolnijBariere($barrier);
        $changed = $moderation->wynik(12);
        $published = $publication->wynik(12);
        $this->assertTrue($changed['ok'], $changed['komunikat']);
        $this->assertSame(BladDlaCzlowieka::class, $published['wyjatek'], $published['komunikat']);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame(1, Notification::query()->where('user_id', $writer->id)->where('type', Notification::TYPE_REPORT_DECIDED)->count());
        $this->assertFalse(Comment::withTrashed()->where('author_id', $writer->id)->where('body', 'Odmowa po moderacji.')->exists());
    }

    public static function dependencies(): iterable
    {
        foreach (['author_id', 'recipe_id', 'parent_id'] as $column) {
            yield $column => [$column];
        }
    }

    #[DataProvider('dependencies')]
    public function test_zmiana_zaleznosci_ponawia_caly_uporzadkowany_zbior(string $column): void
    {
        $writer = $this->konto();
        $owner = $this->konto();
        $nextOwner = $this->konto();
        $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
        $subject = $column === 'recipe_id'
            ? CookedEvent::factory()->for($owner, 'user')->for(Recipe::factory()->for($owner, 'author')->create($attributes))->create()
            : Post::factory()->for($owner, 'author')->create($attributes);
        $root = app(PublishComment::class)->handle($owner, $subject, 'Stary korzeń.');
        $parent = app(PublishComment::class)->handle($owner, $subject, 'Wybrany rodzic.', $root);
        $nextRoot = app(PublishComment::class)->handle($nextOwner, $subject, 'Nowy korzeń.');
        $name = 'dependencies-'.bin2hex(random_bytes(5));
        $barrier = $this->bariera('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$writer->id]);
        $publication = $this->worker('publish', [
            'name' => $name, 'type' => $column === 'recipe_id' ? 'cooked' : 'post', 'subject' => $subject->id,
            'actor' => $writer->id, 'body' => 'Po zmianie zależności.', 'parent' => $parent->id, 'trace' => 'yes',
        ]);
        $this->waitFor($name, $barrier, 'for no key update');
        if ($column === 'author_id') {
            $subject->update(['author_id' => $nextOwner->id]);
        } elseif ($column === 'recipe_id') {
            $recipe = Recipe::factory()->for($nextOwner, 'author')->create($attributes);
            $subject->update(['recipe_id' => $recipe->id]);
        } else {
            $parent->update(['parent_id' => $nextRoot->id]);
        }
        $this->zwolnijBariere($barrier);
        $result = $publication->wynik(12);
        $this->assertTrue($result['ok'], $result['komunikat']);
        $this->assertContains($nextOwner->id, $result['wartosc']['account_locks']);
        $this->assertSame(2, count(array_filter($result['wartosc']['account_locks'], fn ($id) => $id === $writer->id)));
        $comment = Comment::query()->findOrFail($result['wartosc']['id']);
        $this->assertSame($column === 'parent_id' ? $nextRoot->id : $root->id, $comment->parent_id);
        $this->assertSame($column === 'author_id' ? 2 : 1, Notification::query()->where('data->comment_id', $comment->id)->count());
    }

    private function waitFor(string $name, ?PDO $barrier, ?string $sql): int
    {
        $ownerPid = $barrier !== null ? (int) $barrier->query('SELECT pg_backend_pid()')->fetchColumn() : null;
        $query = $this->obserwator->prepare("SELECT pid, query, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        $deadline = microtime(true) + 4;
        do {
            $query->execute([$name]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row && ($sql === null || str_contains(strtolower($row['query']), $sql))) {
                if ($ownerPid !== null) {
                    $this->assertStringContainsString((string) $ownerPid, $row['blockers']);
                    $this->assertNotSame($ownerPid, (int) $row['pid']);
                }

                return (int) $row['pid'];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Nie potwierdzono rzeczywistej bariery procesu '.$name);
    }
}
