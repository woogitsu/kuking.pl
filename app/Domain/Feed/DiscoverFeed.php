<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * "Świeżo z Kuking" — to, co widzi ktoś, kto nikogo jeszcze nie obserwuje.
 *
 * To NIE jest ranking popularności. To chronologia z jednym ograniczeniem:
 * maksymalnie jeden wpis od tej samej osoby w widoku. Bez tego jedna aktywna
 * osoba zasłania cały serwis, a nowy użytkownik odnosi wrażenie, że "tu jest
 * tylko ta pani".
 *
 * Cold start bez tego ekranu nie działa: feed obserwowanych nowego
 * użytkownika jest z definicji pusty (docs/product/COLD_START.md).
 */
final class DiscoverFeed
{
    /** @return CursorPaginator<int, Post> */
    public function paginate(?User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        return Post::query()
            ->publiclyVisible()
            ->when($viewer !== null, fn ($query) => $query->whereNotIn(
                'author_id',
                $this->hiddenAuthorIdsFor($viewer),
            ))
            ->with(['author.profile.avatar', 'media', 'recipe:id,title,slug'])
            ->withCount('comments')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Propozycje osób do obserwowania. Kolejność: kto ostatnio publikował,
     * bo obserwowanie kogoś, kto nic nie wrzuca, nie zapełnia feedu.
     *
     * @return Collection<int, User>
     */
    public function suggestedPeople(?User $viewer, int $limit = 6): Collection
    {
        $excluded = $viewer === null
            ? []
            : [...$this->hiddenAuthorIdsFor($viewer), $viewer->getKey(), ...$viewer->following()->pluck('users.id')->all()];

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', $excluded)
            ->whereHas('posts', fn ($query) => $query->published())
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->published()->latest('published_at')->limit(3)->with('media')])
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();
    }

    /** @return list<string> */
    private function hiddenAuthorIdsFor(User $viewer): array
    {
        return array_values(array_unique([
            ...$viewer->blocking()->pluck('users.id')->all(),
            ...$viewer->blockedBy()->pluck('users.id')->all(),
        ]));
    }
}
