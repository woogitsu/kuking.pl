<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

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
 *
 * Propozycje osób do obserwowania mieszkają w App\Domain\Feed\DailyBoard
 * („kuKINGi na dziś"), bo tam mają kontekst: podgląd zdjęć i ewentualne
 * jedno zdanie od gospodarza.
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

    /** @return list<string> */
    private function hiddenAuthorIdsFor(User $viewer): array
    {
        return array_values(array_unique([
            ...$viewer->blocking()->pluck('users.id')->all(),
            ...$viewer->blockedBy()->pluck('users.id')->all(),
        ]));
    }
}
