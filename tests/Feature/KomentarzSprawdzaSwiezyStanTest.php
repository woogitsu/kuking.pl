<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class KomentarzSprawdzaSwiezyStanTest extends TestCase
{
    use RefreshDatabase;

    public static function unavailableCases(): iterable
    {
        foreach (['post', 'recipe', 'cooked'] as $type) {
            foreach (['private', 'hidden', 'deleted', 'block_forward', 'block_reverse', 'unfollow', 'suspended_writer', 'banned_writer', 'pending_writer', 'erased_writer', 'pending_owner', 'parent_hidden', 'parent_deleted', 'parent_block', 'root_hidden', 'root_deleted', 'root_block'] as $change) {
                yield "$type/$change" => [$type, $change];
            }
        }
    }

    /** @return array{Post|Recipe|CookedEvent, User, Recipe|null} */
    private function subject(string $type): array
    {
        $owner = User::factory()->create();
        $attributes = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];
        if ($type === 'post') {
            return [Post::factory()->for($owner, 'author')->create($attributes), $owner, null];
        }
        $recipe = Recipe::factory()->for($owner, 'author')->create($attributes);
        if ($type === 'recipe') {
            return [$recipe, $owner, $recipe];
        }
        $cook = User::factory()->create();

        return [CookedEvent::factory()->for($cook, 'user')->for($recipe)->create(), $cook, $recipe];
    }

    #[DataProvider('unavailableCases')]
    public function test_stary_model_nie_omija_biezacej_odmowy(string $type, string $change): void
    {
        Queue::fake();
        [$subject, $owner, $recipe] = $this->subject($type);
        $writer = User::factory()->create();
        $rootAuthor = User::factory()->create();
        $parentAuthor = User::factory()->create();
        $action = app(PublishComment::class);
        $root = $action->handle($rootAuthor, $subject, 'Korzeń.');
        $parent = $action->handle($parentAuthor, $subject, 'Rodzic.', $root);
        $positive = $action->handle($writer, $subject, 'Kontrola dodatnia.', $parent);
        $this->assertSame($root->id, $positive->parent_id);
        $this->assertSame(2, Notification::query()->where('data->comment_id', $positive->id)->count());

        $target = $subject instanceof CookedEvent ? $recipe : $subject;
        match ($change) {
            'private' => $target->newQuery()->whereKey($target->id)->update(['visibility' => 'private']),
            'hidden' => $target->newQuery()->whereKey($target->id)->update(['status' => 'hidden']),
            'deleted' => $subject->newQuery()->findOrFail($subject->id)->delete(),
            'block_forward' => app(BlockUser::class)->handle($writer, $owner),
            'block_reverse' => app(BlockUser::class)->handle($owner, $writer),
            'unfollow' => (function () use ($target, $writer): void {
                $target->update(['visibility' => 'followers']);
                $writer->following()->attach($target->author_id);
                app(UnfollowUser::class)->handle($writer, $target->author);
            })(),
            'suspended_writer' => $writer->fresh()->suspend(now()->addDay()),
            'banned_writer' => $writer->fresh()->ban(),
            'pending_writer' => $writer->fresh()->markForDeletion(),
            'erased_writer' => $writer->fresh()->forceFill(['status' => 'erased', 'data_erased_at' => now()])->save(),
            'pending_owner' => $owner->fresh()->markForDeletion(),
            'parent_hidden' => Comment::query()->whereKey($parent->id)->update(['status' => 'hidden']),
            'parent_deleted' => $parent->fresh()->delete(),
            'parent_block' => app(BlockUser::class)->handle($parentAuthor, $writer),
            'root_hidden' => Comment::query()->whereKey($root->id)->update(['status' => 'hidden']),
            'root_deleted' => $root->fresh()->delete(),
            'root_block' => app(BlockUser::class)->handle($writer, $rootAuthor),
        };
        $comments = Comment::withTrashed()->count();
        $notifications = Notification::query()->count();
        $denied = false;
        try {
            $action->handle($writer, $subject, 'Nie wolno zapisać.', $parent);
        } catch (BladDlaCzlowieka $e) {
            $denied = true;
            $this->assertStringContainsString('Odśwież stronę', $e->getMessage());
        }
        $this->assertSame($comments, Comment::withTrashed()->count());
        $this->assertSame($notifications, Notification::query()->count());
        $this->assertTrue($denied, 'Nieaktualny model ominął odmowę.');
    }

    public static function types(): iterable
    {
        foreach (['post', 'recipe', 'cooked'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('types')]
    public function test_powiadomienia_sa_atomowe_i_powtorzenie_nie_dubluje(string $type): void
    {
        Queue::fake();
        [$subject] = $this->subject($type);
        $parent = app(PublishComment::class)->handle(User::factory()->create(), $subject, 'Pierwszy.');
        $writer = User::factory()->create();
        $armed = true;
        $calls = 0;
        Notification::creating(function () use (&$armed, &$calls): void {
            if ($armed && ++$calls === 2) {
                throw new RuntimeException('Kontrolowana awaria drugiego powiadomienia.');
            }
        });
        $before = [Comment::query()->count(), Notification::query()->count()];
        try {
            app(PublishComment::class)->handle($writer, $subject, 'Odpowiedź.', $parent);
            $this->fail('Oczekiwana awaria.');
        } catch (RuntimeException $e) {
            $this->assertSame('Kontrolowana awaria drugiego powiadomienia.', $e->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame($before, [Comment::query()->count(), Notification::query()->count()]);
        $first = app(PublishComment::class)->handle($writer, $subject, 'Odpowiedź.', $parent);
        $second = app(PublishComment::class)->handle($writer, $subject, 'Odpowiedź.', $parent);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, Notification::query()->where('data->comment_id', $first->id)->count());
    }

    #[DataProvider('types')]
    public function test_wlasna_tresc_i_placeholder_zachowuja_dotychczasowe_reguly(string $type): void
    {
        Queue::fake();
        [$subject, $owner, $recipe] = $this->subject($type);
        $root = app(PublishComment::class)->handle($owner, $subject, 'Własny głos.');
        $this->assertSame(0, Notification::query()->where('data->comment_id', $root->id)->count());
        $writer = User::factory()->create();
        app(PublishComment::class)->handle($writer, $subject, 'Odpowiedź.', $root);
        app(DeleteComment::class)->handle($owner, $root);
        $this->assertSame('Komentarz usunięty.', $root->fresh()->body);
        $this->assertNotNull($root->fresh()->body_removed_at);
        $next = app(PublishComment::class)->handle($writer, $subject, 'Pod śladem.', $root);
        $this->assertSame($root->id, $next->parent_id);
        ($subject instanceof CookedEvent ? $recipe : $subject)->update(['visibility' => 'private']);
        $own = app(PublishComment::class)->handle($owner, $subject, 'Nadal własna treść.');
        $this->assertNotNull($own->id);
    }

    public static function httpRoutes(): iterable
    {
        foreach (['posts.comment', 'recipes.comment', 'cooked.comment', 'cooked.thank', 'admin.unanswered.reply'] as $route) {
            yield $route => [$route];
        }
    }

    #[DataProvider('httpRoutes')]
    public function test_piec_drog_http_zachowuje_tekst_po_nowej_odmowie(string $route): void
    {
        Queue::fake();
        $type = str_starts_with($route, 'cooked.') ? 'cooked' : ($route === 'recipes.comment' ? 'recipe' : 'post');
        [$subject, $owner, $recipe] = $this->subject($type);
        $writer = $route === 'cooked.thank' ? $recipe->author : ($route === 'admin.unanswered.reply' ? $this->moderator() : $this->user());
        $this->actingAs($writer)->from('/home')->post(route($route, $subject), ['body' => 'Dodatnia kontrola HTTP.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Comment::query()->where('body', 'Dodatnia kontrola HTTP.')->where('author_id', $writer->id)->count());
        $armed = true;
        if ($route === 'admin.unanswered.reply') {
            DB::listen(function ($query) use (&$armed, $writer): void {
                if ($armed && str_contains($query->sql, 'select exists') && str_contains($query->sql, '"posts"')) {
                    $armed = false;
                    $writer->fresh()->suspend(now()->addDay());
                }
            });
        } else {
            Gate::after(function ($user, $ability, $result) use (&$armed, $writer, $owner): void {
                if ($armed && $result === true && in_array($ability, ['comment', 'view', 'celebrate'], true)) {
                    $armed = false;
                    app(BlockUser::class)->handle($owner, $writer);
                }
            });
        }
        $before = [Comment::query()->count(), Notification::query()->count()];
        try {
            $this->actingAs($writer)->from('/home')->post(route($route, $subject), ['body' => 'Zachowaj wpisany tekst.'])
                ->assertRedirect('/home')->assertSessionHasErrors('body')->assertSessionHasInput('body', 'Zachowaj wpisany tekst.');
            $this->assertFalse($armed, 'Nie osiągnięto bariery po wstępnej kontroli.');
            $this->assertSame($before, [Comment::query()->count(), Notification::query()->count()]);
        } finally {
            $armed = false;
        }
    }

    public function test_blokada_autora_przepisu_w_obu_kierunkach_i_statusy_kucharza(): void
    {
        Queue::fake();
        foreach ([false, true] as $reverse) {
            [$subject, $cook, $recipe] = $this->subject('cooked');
            $writer = User::factory()->create();
            $positive = app(PublishComment::class)->handle($writer, $subject, 'Kontrola przepisu.');
            $this->assertSame(1, Notification::query()->where('data->comment_id', $positive->id)->count());
            app(BlockUser::class)->handle($reverse ? $recipe->author : $writer, $reverse ? $writer : $recipe->author);
            try {
                app(PublishComment::class)->handle($writer, $subject, 'Nie po blokadzie.');
                $this->fail('Blokada autora przepisu została pominięta.');
            } catch (BladDlaCzlowieka) {
                $this->assertSame(1, $subject->comments()->count());
            }
        }

        // Decyzja właściciela do D-261 (25.09.2026): „Nie, komentarze
        // zostają”. Ban chowa wykonanie przed obcymi, ale komentowania nie
        // zamyka — stąd `banned` na tej liście (`CookedEventPolicy::comment()`).
        foreach (['active', 'suspended', 'banned', 'erased'] as $status) {
            [$subject, $cook] = $this->subject('cooked');
            $cook->forceFill(['status' => $status, 'data_erased_at' => $status === 'erased' ? now() : null])->save();
            $comment = app(PublishComment::class)->handle(User::factory()->create(), $subject, 'Widoczna historia kucharza.');
            $this->assertNotNull($comment->id);
        }

        // Kontrola ujemna: wyjątek dotyczy tylko bana. Kucharz w karencji
        // usunięcia zamyka także komentowanie.
        [$subject, $cook] = $this->subject('cooked');
        $cook->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        try {
            app(PublishComment::class)->handle(User::factory()->create(), $subject, 'Nie pod wykonaniem w karencji.');
            $this->fail('Komentarz pod wykonaniem kucharza w karencji usunięcia przeszedł.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame(0, $subject->comments()->count());
        }
    }

    #[DataProvider('types')]
    public function test_moderator_zachowuje_istniejacy_wyjatek_ukrytej_tresci(string $type): void
    {
        Queue::fake();
        [$subject, , $recipe] = $this->subject($type);
        $moderator = $this->moderator();
        $positive = app(PublishComment::class)->handle($moderator, $subject, 'Komentarz moderatora przy publicznej treści.');
        $this->assertNotNull($positive->id);
        ($recipe ?? $subject)->update(['status' => 'hidden']);
        if ($type === 'post') {
            // PostPolicy świadomie nie ma furtki moderatora dla hidden.
            $this->expectException(BladDlaCzlowieka::class);
        }
        $comment = app(PublishComment::class)->handle($moderator, $subject, 'Komentarz moderatora.');
        $this->assertNotNull($comment->id);
    }

    public function test_trzecia_zmiana_zaleznosci_konczy_sie_odmowa_i_pelnym_rollbackiem(): void
    {
        Queue::fake();
        [$subject, $owner] = $this->subject('post');
        $writer = User::factory()->create();
        $nextOwner = User::factory()->create();
        $attempts = 0;
        $armed = true;
        DB::listen(function ($query) use (&$armed, &$attempts, $subject, $writer, $nextOwner): void {
            if ($armed && str_contains($query->sql, '"users"') && str_contains($query->sql, 'FOR NO KEY UPDATE') && $query->bindings === [$writer->id]) {
                $attempts++;
                Post::query()->whereKey($subject->id)->update(['author_id' => $nextOwner->id]);
            }
        });
        try {
            app(PublishComment::class)->handle($writer, $subject, 'Nie po trzech zmianach.');
            $this->fail('Ciągle zmieniające się zależności nie zakończyły próby.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame(3, $attempts);
            $this->assertSame($owner->id, $subject->fresh()->author_id);
            $this->assertSame(0, Comment::query()->count());
            $this->assertSame(0, Notification::query()->count());
        } finally {
            $armed = false;
        }
        $positive = app(PublishComment::class)->handle($writer, $subject, 'Po wyłączeniu zmiany.');
        $this->assertSame(1, Notification::query()->where('data->comment_id', $positive->id)->count());
    }
}
