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
            ->enabledKinds()
            ->published()
            ->whereIn('author_id', $authorIds)
            // Wpisy "tylko dla obserwujących" widzi obserwujący i autor.
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            // TEGO TU NIE BYŁO, a `isEmptyFor()` kilkanaście linijek niżej
            // stosowało to od początku. Skutek: wpis zbanowanego autora
            // znikał spod własnego adresu (`PostPolicy::view` dawał 403),
            // ale dalej stał w feedzie każdego, kto tę osobę obserwował —
            // ze zdjęciem, nazwą i treścią. Ta sama usterka co W5-08
            // (zeszyt i mapa strony), tylko w innym miejscu i o dwie metody
            // od kodu, który regułę znał.
            //
            // Wąski próg (`status = active`), nie `jestDostepnyJakoAutor()`:
            // taki stosuje `isEmptyFor()`, a te dwie metody MUSZĄ się
            // zgadzać — inaczej feed złożony wyłącznie z wpisów osoby
            // zawieszonej meldowałby „pusto" i jednocześnie coś pokazywał.
            // Poluzowanie tego do granicy z polityki (czyli wpuszczenie
            // zawieszonych) to osobna decyzja, nie poprawka luki.
            ->tylkoOdAktywnychAutorow()
            // WPIS WSKAZUJĄCY PRZEPIS WYCHODZI TYLKO Z WIDOCZNYM PRZEPISEM
            // (issue #368). Widoczność liczy się Z PRZEPISU, nie z kopii na
            // wpisie — patrz `Post::scopeZWidocznymPrzepisem()`.
            ->zWidocznymPrzepisem($viewer)
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($viewer)
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
            ->enabledKinds()
            ->published()
            ->whereIn('author_id', $viewer->following()->pluck('users.id')->all())
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->tylkoOdAktywnychAutorow()
            // TEN SAM WARUNEK CO W `paginate()` (issue #368) i z tego samego
            // powodu, dla którego stoi tu `tylkoOdAktywnychAutorow()`: te dwie
            // metody MUSZĄ się zgadzać. Inaczej feed złożony wyłącznie
            // z wpisów do przepisów schowanych przez moderację meldowałby
            // „pusto" i jednocześnie coś pokazywał — albo odwrotnie.
            ->zWidocznymPrzepisem($viewer)
            ->doesntExist();
    }
}
