<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\PaginationState;
use Tests\TestCase;

/** Wspólny oracle semantyki dla pomiarów IN / EXISTS / JOIN, nie benchmark. */
class PomiarFeeduGraniceTest extends TestCase
{
    use RefreshDatabase;

    public function test_macierz_widocznosci_ma_jawne_id_i_niezerowe_liczniki(): void
    {
        $widz = $this->user();
        $autor = $this->user();
        $obcy = $this->user();
        $blokowany = $this->user();
        $blokujacy = $this->user();
        $ukarany = $this->user();
        foreach ([$autor, $blokowany, $blokujacy, $ukarany] as $osoba) {
            app(FollowUser::class)->handle($widz, $osoba);
        }

        $wlasny = $this->wpis($widz, 10);
        $obserwujacy = $this->wpis($autor, 20, ['visibility' => 'followers']);
        $publiczny = $this->wpis($autor, 30);
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'followers']);
        $zPrzepisem = $this->wpis($autor, 40, ['recipe_id' => $przepis->id]);

        $this->wpis($autor, 50, ['visibility' => 'private']);
        $this->wpis($widz, 51, ['visibility' => 'private']);
        $this->wpis($obcy, 52);
        $this->wpis($autor, 53, ['status' => Post::STATUS_HIDDEN]);
        $this->wpis($autor, 54)->delete();
        $this->wpis($autor, 55, ['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        $this->wpis($blokowany, 56);
        $this->wpis($blokujacy, 57);
        $this->wpis($ukarany, 58);
        foreach (['hidden', 'deleted', 'private'] as $i => $stan) {
            $niedostepny = Recipe::factory()->create(['author_id' => $autor->id]);
            $this->wpis($autor, 60 + $i, ['recipe_id' => $niedostepny->id]);
            if ($stan === 'deleted') {
                $niedostepny->delete();
            } else {
                $niedostepny->update($stan === 'hidden' ? ['status' => Recipe::STATUS_HIDDEN] : ['visibility' => 'private']);
            }
        }

        $zapisz = app(SavePostToCollection::class);
        foreach ([$autor, $widz, $obcy, $blokowany, $blokujacy, $ukarany] as $osoba) {
            $zapisz->handle($osoba, $publiczny);
            Comment::create(['author_id' => $osoba->id, 'post_id' => $publiczny->id, 'body' => 'Komentarz do pomiaru.', 'status' => Comment::STATUS_PUBLISHED]);
        }
        $drugi = $obcy->collections()->create(['name' => 'Drugi zeszyt', 'visibility' => 'private']);
        $zapisz->handle($obcy, $publiczny, $drugi);
        $zapisz->handle($widz, $wlasny); // Własny zapis nie podbija liczby.
        $zapisz->handle($autor, $wlasny);
        Comment::create(['author_id' => $autor->id, 'post_id' => $publiczny->id, 'body' => 'Ukryty komentarz.', 'status' => Comment::STATUS_HIDDEN]);

        app(BlockUser::class)->handle($widz, $blokowany);
        app(BlockUser::class)->handle($blokujacy, $widz);
        // Status jest chroniony przed mass assignment (User::$fillable).
        // Fixture musi naprawdę zmienić stan konta, nie tylko wywołać update().
        $ukarany->forceFill(['status' => User::STATUS_BANNED])->save();
        $this->assertSame(User::STATUS_BANNED, $ukarany->fresh()->status);
        $zawieszony = $this->user();
        app(FollowUser::class)->handle($widz, $zawieszony);
        $this->wpis($zawieszony, 70);
        $zawieszony->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertSame(User::STATUS_SUSPENDED, $zawieszony->fresh()->status);

        $feed = app(FollowingFeed::class)->paginate($widz->fresh(), 100);
        $this->assertSame([$zPrzepisem->id, $publiczny->id, $obserwujacy->id, $wlasny->id], $feed->getCollection()->pluck('id')->all());
        $this->assertFalse(app(FollowingFeed::class)->isEmptyFor($widz->fresh()));
        $wpis = $feed->getCollection()->firstWhere('id', $publiczny->id);
        $this->assertSame(3, (int) $wpis->comments_count); // Autor, widz, obcy.
        $this->assertSame(2, (int) $wpis->getAttribute('zapisow_count')); // Widz i obcy, mimo dwóch zeszytów.
        $this->assertTrue((bool) $wpis->getAttribute('czy_zapisany'));
        $moj = $feed->getCollection()->firstWhere('id', $wlasny->id);
        $this->assertSame(1, (int) $moj->getAttribute('zapisow_count'));
        $this->assertTrue((bool) $moj->getAttribute('czy_zapisany'));
        $this->assertFalse((bool) $feed->getCollection()->firstWhere('id', $obserwujacy->id)->getAttribute('czy_zapisany'));
        foreach ($feed->items() as $item) {
            foreach (['author', 'media', 'recipe', 'tags'] as $relation) {
                $this->assertTrue($item->relationLoaded($relation), $relation);
            }
            $this->assertTrue($item->author->relationLoaded('profile'));
            $this->assertTrue($item->author->profile->relationLoaded('avatar'));
        }
        $loadedRecipe = $feed->getCollection()->firstWhere('id', $zPrzepisem->id)->recipe;
        $this->assertSame('followers', $loadedRecipe->visibility);
        $this->assertTrue($loadedRecipe->relationLoaded('heroMedia'));
    }

    public function test_kursor_przechodzi_remis_w_obie_strony_bez_duplikatow(): void
    {
        $widz = $this->user();
        $autor = $this->user();
        app(FollowUser::class)->handle($widz, $autor);
        foreach ([1, 2, 3, 4, 5] as $numer) {
            $this->wpis($autor, $numer);
        }
        // Nie wyliczamy oczekiwanej kolejności sortowaniem wyniku feedu.
        try {
            CursorPaginator::currentCursorResolver(fn () => null);
            $pierwsza = app(FollowingFeed::class)->paginate($widz, 2);
            $this->assertSame([$this->id(5), $this->id(4)], $pierwsza->getCollection()->pluck('id')->all());
            $cursor = $pierwsza->nextCursor();
            $this->assertNotNull($cursor);
            CursorPaginator::currentCursorResolver(fn () => Cursor::fromEncoded($cursor->encode()));
            $druga = app(FollowingFeed::class)->paginate($widz, 2);
            $this->assertSame([$this->id(3), $this->id(2)], $druga->getCollection()->pluck('id')->all());
            $next = $druga->nextCursor();
            $previous = $druga->previousCursor();
            $this->assertNotNull($next);
            $this->assertNotNull($previous);
            CursorPaginator::currentCursorResolver(fn () => Cursor::fromEncoded($next->encode()));
            $trzecia = app(FollowingFeed::class)->paginate($widz, 2);
            $this->assertSame([$this->id(1)], $trzecia->getCollection()->pluck('id')->all());
            $this->assertNull($trzecia->nextCursor());
            CursorPaginator::currentCursorResolver(fn () => Cursor::fromEncoded($previous->encode()));
            $powrot = app(FollowingFeed::class)->paginate($widz, 2);
            $this->assertSame([$this->id(5), $this->id(4)], $powrot->getCollection()->pluck('id')->all());
        } finally {
            // Przywrócenie resolverów Laravela, nie pozostawienie ostatniego kursora w suicie.
            PaginationState::resolveUsing($this->app);
        }
    }

    public function test_wlasne_wpisy_nie_wylaczaja_stanu_pustego_feedu_obserwowanych(): void
    {
        $widz = $this->user();
        $wpis = $this->wpis($widz, 1);
        $feed = app(FollowingFeed::class);
        $this->assertSame([$wpis->id], $feed->paginate($widz)->getCollection()->pluck('id')->all());
        $this->assertTrue($feed->isEmptyFor($widz));
        $autor = $this->user();
        app(FollowUser::class)->handle($widz, $autor);
        $this->wpis($autor, 2, ['status' => Post::STATUS_HIDDEN]);
        $this->assertTrue($feed->isEmptyFor($widz->fresh()));
        $widoczny = $this->wpis($autor, 3);
        $this->assertFalse($feed->isEmptyFor($widz->fresh()));
        $this->assertSame([$widoczny->id, $wpis->id], $feed->paginate($widz->fresh())->getCollection()->pluck('id')->all());
    }

    private function id(int $numer): string
    {
        return sprintf('10000000-0000-4000-8000-%012d', $numer);
    }

    /** @param array<string, mixed> $attributes */
    private function wpis(User $autor, int $numer, array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'id' => $this->id($numer),
            'author_id' => $autor->id,
            'published_at' => '2026-09-01 12:00:00+00',
        ], $attributes));
    }
}
