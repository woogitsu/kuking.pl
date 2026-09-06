<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Feed obserwowanych — chronologicznie, bez algorytmu.
 *
 * Dlaczego chronologicznie: przewidywalność. Osoba, która wczoraj widziała
 * wpis koleżanki na górze, dziś ma go znaleźć niżej, a nie "gdzieś".
 * Algorytmiczny feed wymaga danych, których nie mamy, i natychmiast dzieli
 * użytkowników na tych "widzianych" i "niewidzianych".
 *
 * Zapytanie jest celowo proste: WHERE author_id IN (...) + kursor.
 * Żadnego fanout-on-write, żadnej osobnej tabeli feedu — dopóki pomiar nie
 * pokaże, że jest potrzebna (docs/ARCHITECTURE.md).
 */
final class FollowingFeed
{
    /** @return CursorPaginator<int, Post> */
    public function paginate(User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $followedIds = $viewer->following()->pluck('users.id')->all();

        // Własne wpisy też są w feedzie — inaczej po pierwszej publikacji
        // użytkownik widzi pustkę i myśli, że nic się nie zapisało.
        $authorIds = array_values(array_unique([...$followedIds, $viewer->getKey()]));

        return Post::query()
            ->published()
            ->whereIn('author_id', $authorIds)
            // Wpisy "tylko dla obserwujących" widzi obserwujący i autor.
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug',
            ])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Czy feed obserwowanych nie ma nic do pokazania POZA własnymi wpisami
     * tej osoby.
     *
     * NAZWA MÓWIŁA JEDNO, KOD PYTAŁ O DRUGIE
     * Wcześniej brzmiało to `following()->doesntExist() && posts()->doesntExist()`
     * — czyli „czy ten człowiek zrobił już cokolwiek", a nie „czy feed jest
     * pusty". Skutek: osoba, która opublikowała JEDEN wpis i nikogo nie
     * obserwuje, dostawała feed złożony wyłącznie z własnego wpisu, a blok
     * „Świeżo z Kuking" znikał jej z ekranu NA ZAWSZE. Pierwsza publikacja
     * odcinała ją od reszty serwisu — dokładnie odwrotnie, niż powinna.
     *
     * Teraz pytamy o treść: czy jest tu cokolwiek od kogoś innego.
     */
    public function isEmptyFor(User $viewer): bool
    {
        return Post::query()
            ->published()
            ->whereIn('author_id', $viewer->following()->pluck('users.id')->all())
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->tylkoOdDostepnychAutorow()
            ->doesntExist();
    }
}
