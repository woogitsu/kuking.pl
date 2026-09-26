<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Collections\ZapisyWpisu;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Feed obserwowanych — osoby RAZEM z tematami, chronologicznie, bez algorytmu.
 *
 * OSOBY I TEMATY W JEDNEJ LIŚCIE (issue #1808, D-277, zmienia D-021)
 * Do 25 września 2026 Start wybierał JEDNO źródło: obserwowani → tagi →
 * Odkrywanie. Kto obserwował choć jedną aktywną osobę, nie widział nigdy
 * wpisów z obserwowanych tematów — „Obserwuj temat" było dla niego bez
 * skutku. Teraz lista jest sumą: wpisy obserwowanych osób (publiczne i „tylko
 * dla obserwujących") ORAZ publiczne wpisy z obserwowanych tematów, po czasie,
 * bez duplikatów. Karta z tagu nosi podpis „Z tagu: …" (słowo „tag" — JednoSlowoNaTagiTest) — źródło jest
 * zawsze nazwane (AGENTS.md §8: jawne polecenia widza).
 *
 * Dlaczego chronologicznie: przewidywalność. Osoba, która wczoraj widziała
 * wpis koleżanki na górze, dziś ma go znaleźć niżej, a nie "gdzieś".
 * Algorytmiczny feed wymaga danych, których nie mamy, i natychmiast dzieli
 * użytkowników na tych "widzianych" i "niewidzianych".
 *
 * Zapytanie jest celowo proste: WHERE author_id IN (...) OR (publiczny
 * AND EXISTS obserwowany tag) + kursor.
 * Żadnego fanout-on-write, żadnej osobnej tabeli feedu — dopóki pomiar nie
 * pokaże, że jest potrzebna (docs/ARCHITECTURE.md).
 */
final class FollowingFeed
{
    /**
     * `new ZapisyWpisu` jako domyślna wartość — tak samo jak
     * `LiczbaKukingow` bierze `CookEligibility`. Kontener i tak wstrzyknie
     * tę klasę (nie ma zależności), a domyślna wartość sprawia, że test
     * wołający `new FollowingFeed` wprost nie musi o niej wiedzieć.
     */
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

    /** @return CursorPaginator<int, Post> */
    public function paginate(User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $followedIds = $viewer->following()->pluck('users.id')->all();
        $tagIds = $this->obserwowaneTematy($viewer);

        // Własne wpisy też są w feedzie — inaczej po pierwszej publikacji
        // użytkownik widzi pustkę i myśli, że nic się nie zapisało.
        $authorIds = array_values(array_unique([...$followedIds, $viewer->getKey()]));

        $strona = $this->zrodla(Post::query(), $viewer, $authorIds, $tagIds, zWlasnymi: true)
            ->with([
                'author.profile.avatar',
                'media',
                // `visibility` i `hero_media_id` W SELEKCIE, a `heroMedia`
                // doładowane (issue #368): karta wpisu wskazującego przepis
                // bierze z relacji WSZYSTKO — tytuł, zdjęcie i plakietkę
                // widoczności — bo wpis niczego z przepisu nie kopiuje.
                // Kolumna pominięta w selekcie wróciłaby jako `null`, czyli
                // karta po cichu napisałaby „publicznie" pod przepisem
                // widocznym tylko dla obserwujących.
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
                // Bez tego karta wpisu (post-card.blade.php) nie pokaże
                // tematów tego wpisu — `relationLoaded()` tam celowo NIE
                // dociąga ich sama, żeby nie odpalić zapytania per wpis.
                'tags:id,slug,name,status',
            ])
            ->withVisibleCommentCount($viewer)
            // Liczba zapisów i stan „mam to w zeszycie" — TYM SAMYM
            // zapytaniem, co wszystko powyżej (issue #275, D-081). Reguły
            // (kto się liczy, od ilu osób widać liczbę) siedzą w
            // `ZapisyWpisu`; tutaj jest tylko miejsce, w którym dokładamy
            // kolumnę do SELECT-a. Bez tego karta wpisu nie pokazałaby ani
            // liczby, ani potwierdzenia — dokładnie jak z `tags:id,slug,name,status`
            // wyżej.
            ->tap(fn ($q) => $this->zapisy->dolicz($q, $viewer))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        // Wpis z własną treścią idzie za WŁASNĄ widocznością (issue #1377);
        // niedostępny przepis zdejmujemy tylko z jego karty.
        Post::ukryjNiedostepnePrzepisy($strona->items(), $viewer);

        $this->podpiszTematy($strona->getCollection(), $authorIds, $tagIds);

        return $strona;
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
        // TE SAME ŹRÓDŁA I BRAMKI CO W `paginate()` (issue #368, #1808) —
        // te dwie metody MUSZĄ się zgadzać. Inaczej feed złożony wyłącznie
        // z wpisów, których `paginate()` nie odda (np. do przepisów schowanych
        // przez moderację), meldowałby „jest treść", a Start pokazywałby pustkę
        // — albo odwrotnie. Jedyna różnica: własne wpisy się tu nie liczą.
        return $this->zrodla(
            Post::query(),
            $viewer,
            $viewer->following()->pluck('users.id')->all(),
            $this->obserwowaneTematy($viewer),
            zWlasnymi: false,
        )->doesntExist();
    }

    /**
     * Źródła i bramki listy — jedno miejsce dla `paginate()` i `isEmptyFor()`.
     *
     * @param  Builder<Post>  $query
     * @param  list<string>  $authorIds
     * @param  list<string>  $tagIds
     * @return Builder<Post>
     */
    private function zrodla(Builder $query, User $viewer, array $authorIds, array $tagIds, bool $zWlasnymi): Builder
    {
        return $query
            ->enabledKinds()
            ->published()
            ->where(function (Builder $zrodla) use ($viewer, $authorIds, $tagIds, $zWlasnymi): void {
                // 1. Obserwowane osoby (i widz): publiczne oraz „tylko dla
                //    obserwujących" — te drugie widzi obserwujący i autor.
                //    Blokada kasuje obserwowanie w obie strony, więc osobnej
                //    bramki blokad ta gałąź nie potrzebuje.
                $zrodla->where(fn (Builder $osoby) => $osoby
                    ->whereIn('posts.author_id', $authorIds)
                    ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS]));

                if ($tagIds === []) {
                    return;
                }

                // 2. Obserwowane tematy: WYŁĄCZNIE wpisy publiczne. Obserwowanie
                //    tematu nie jest relacją z autorem, więc nie otwiera „tylko
                //    dla obserwujących" ani prywatnych — także własnych (te
                //    wchodzą gałęzią pierwszą, z jej regułami). `widoczneDla()`
                //    dokłada blokady w OBIE strony: temat nie może być obejściem
                //    blokady. `whereHas` to `EXISTS`, nie `JOIN` — wpis z trzema
                //    obserwowanymi tematami wychodzi raz (SPEC §1.9).
                //    Tylko tematy aktywne: temat ukryty albo scalony przez
                //    moderację nie prowadzi już wpisów na Start.
                $zrodla->orWhere(fn (Builder $tematy) => $tematy
                    ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                    ->whereHas('tags', fn ($q) => $q
                        ->whereIn('tags.id', $tagIds)
                        ->where('tags.status', Tag::STATUS_ACTIVE))
                    ->widoczneDla($viewer)
                    // „Ukryj tę osobę" (#1810, D-278, decyzja właściciela
                    // 26.09) działa też tutaj: wpis z tagu PODSUWA autora,
                    // którego widz nie wybrał. Tylko w tej gałęzi — osoby
                    // obserwowane wprost (gałąź 1.) zostają zawsze widoczne,
                    // także gdy ich wpis ma obserwowany tag.
                    ->bezUkrytychOsob($viewer)
                    ->when(! $zWlasnymi, fn (Builder $q) => $q->where('posts.author_id', '!=', $viewer->getKey())));
            })
            // Wąski próg (`status = active`), nie `jestDostepnyJakoAutor()`,
            // dla OBU gałęzi. Do #1808 stał tu komentarz o luce, przez którą
            // wpis zbanowanego autora stał w feedzie każdego, kto tę osobę
            // obserwował — ta sama usterka co W5-08 (zeszyt i mapa strony).
            // Poluzowanie tego do granicy z polityki (czyli wpuszczenie
            // zawieszonych) to osobna decyzja, nie poprawka luki.
            ->tylkoOdAktywnychAutorow()
            // „Ukryj ten wpis" (#1810, D-278) — jawne polecenie widza, dla obu
            // gałęzi. Ukrycie OSOBY działa tylko w gałęzi tagów (wyżej): osób
            // obserwowanych wprost się nie ukrywa (AGENTS.md §8).
            ->bezUkrytychWpisow($viewer)
            // WPIS WSKAZUJĄCY PRZEPIS WYCHODZI TYLKO Z WIDOCZNYM PRZEPISEM
            // (issue #368). Widoczność liczy się Z PRZEPISU, nie z kopii na
            // wpisie — patrz `Post::scopeZWidocznymPrzepisem()`. Dla gałęzi
            // tematów to jest druga, nienadmiarowa bramka: zapowiedź przepisu
            // ma na stałe `visibility = public`, a widoczność trzyma przepis.
            // Wpis z własną treścią idzie za własną widocznością (issue #1377).
            ->zWidocznymPrzepisemAlboWlasnaTrescia($viewer);
    }

    /**
     * Podpis „Z tagu: …" na kartach, które przyszły TYLKO z obserwowanego tagu.
     *
     * Wpis obserwowanej osoby (albo własny) podpisu nie dostaje, nawet jeśli
     * ma obserwowany temat — przyszedł od osoby, a osoba jest ważniejsza od
     * kategorii. Temat do podpisu: pierwszy obserwowany i aktywny w kolejności
     * tematów wpisu. Relacja `zrodloTematu` to tylko nośnik dla widoku (karta
     * pyta `relationLoaded()`), nie kolumna — niczego się nie zapisuje.
     *
     * @param  Collection<int, Post>  $posty
     * @param  list<string>  $authorIds
     * @param  list<string>  $tagIds
     */
    private function podpiszTematy(Collection $posty, array $authorIds, array $tagIds): void
    {
        foreach ($posty as $post) {
            if (in_array($post->author_id, $authorIds, true)) {
                continue;
            }

            $temat = $post->tags->first(fn (Tag $tag) => $tag->status === Tag::STATUS_ACTIVE
                && in_array($tag->getKey(), $tagIds, true));

            $post->setRelation('zrodloTematu', $temat);
        }
    }

    /** @return list<string> */
    private function obserwowaneTematy(User $viewer): array
    {
        // TYLKO AKTYWNE (issue #1824). Tag ukryty przez moderację po tym, jak
        // ktoś zaczął go obserwować, ma 404 na własnej stronie i znika
        // z katalogu, ale wiersz w `tag_follows` zostaje — świadomie, żeby
        // człowiek mógł go sam zdjąć w „Twoich tagach”. Taki temat nie może
        // sterować Startem: ani zasilać listy, ani decydować w `isEmptyFor()`.
        // Warunek stoi tutaj, nie w relacji `followedTags()`: ekran ustawień
        // musi nadal widzieć zastany ukryty tag, żeby dało się go usunąć.
        return $viewer->followedTags()
            ->where('tags.status', Tag::STATUS_ACTIVE)
            ->pluck('tags.id')
            ->all();
    }
}
