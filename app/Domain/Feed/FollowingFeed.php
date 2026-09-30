<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Social\ListyWidza;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\KursorListy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
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
 * AND EXISTS obserwowany tag) + kursor. Bramki widoczności i blokad
 * (`Post::widoczneDla()`) stoją w TYM SAMYM zapytaniu, dla obu gałęzi —
 * patrz `zrodla()`, issue #2026.
 * Żadnego fanout-on-write, żadnej osobnej tabeli feedu — dopóki pomiar nie
 * pokaże, że jest potrzebna (docs/ARCHITECTURE.md).
 */
final class FollowingFeed
{
    public function __construct(private readonly ListyWidza $listy) {}

    /** @return CursorPaginator<int, Post> */
    public function paginate(User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $followedIds = $this->listy->osoby($viewer);
        $tagIds = $this->listy->tagiAktywne($viewer);

        // Własne wpisy też są w feedzie — inaczej po pierwszej publikacji
        // użytkownik widzi pustkę i myśli, że nic się nie zapisało.
        $authorIds = array_values(array_unique([...$followedIds, $viewer->getKey()]));

        $strona = $this->zrodla(Post::query(), $viewer, $authorIds, $tagIds, zWlasnymi: true)
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            // Kursor z adresu przez `KursorListy` (#2308): niepełny albo
            // zmyślony daje pierwszą stronę, nie HTTP 500.
            ->pipe(fn ($zapytanie) => KursorListy::strona($zapytanie, $perPage, ['published_at' => KursorListy::CZAS, 'id' => KursorListy::UUID]));

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
            $this->listy->osoby($viewer),
            $this->listy->tagiAktywne($viewer),
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
                //    Lista `$authorIds` to tylko ZAWĘŻENIE źródła, nie
                //    uprawnienie: bramki blokad i obserwowania liczy
                //    `widoczneDla()` niżej, w chwili tego zapytania (#2026).
                $zrodla->where(fn (Builder $osoby) => $osoby
                    ->whereIn('posts.author_id', $authorIds)
                    ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS]));

                if ($tagIds === []) {
                    return;
                }

                // 2. Obserwowane tematy: WYŁĄCZNIE wpisy publiczne. Obserwowanie
                //    tematu nie jest relacją z autorem, więc nie otwiera „tylko
                //    dla obserwujących" ani prywatnych — także własnych (te
                //    wchodzą gałęzią pierwszą, z jej regułami). Blokady w OBIE
                //    strony dokłada `widoczneDla()` niżej, wspólne dla obu
                //    gałęzi: temat nie może być obejściem blokady.
                //    `whereHas` to `EXISTS`, nie `JOIN` — wpis z trzema
                //    obserwowanymi tematami wychodzi raz (SPEC §1.9).
                //    Tylko tematy aktywne: temat ukryty albo scalony przez
                //    moderację nie prowadzi już wpisów na Start.
                $zrodla->orWhere(fn (Builder $tematy) => $tematy
                    ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                    // `IN (podzapytanie)` zamiast `whereHas` (skorelowany `EXISTS`
                    // w alternatywie planer nalicza za każdy wiersz — #599).
                    ->whereIn('posts.id', fn ($q) => $q
                        ->select('post_tags.post_id')
                        ->from('post_tags')
                        ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
                        ->whereIn('post_tags.tag_id', $tagIds)
                        ->where('tags.status', Tag::STATUS_ACTIVE))
                    // „Ukryj tę osobę" (#1810, D-278, decyzja właściciela
                    // 26.09) działa też tutaj: wpis z tagu PODSUWA autora,
                    // którego widz nie wybrał. Tylko w tej gałęzi — osoby
                    // obserwowane wprost (gałąź 1.) zostają zawsze widoczne,
                    // także gdy ich wpis ma obserwowany tag.
                    ->bezUkrytychOsob($viewer, bezKorelacji: true)
                    ->when(! $zWlasnymi, fn (Builder $q) => $q->where('posts.author_id', '!=', $viewer->getKey())));
            })
            // BLOKADA I OBSERWOWANIE W CHWILI ZAPYTANIA (issue #2026), dla OBU
            // gałęzi. Do #2026 gałąź osób ufała liście `$authorIds` pobranej
            // osobnym zapytaniem („blokada kasuje obserwowanie"). Blokada
            // zatwierdzona między tamtym odczytem a tym zapytaniem (READ
            // COMMITTED) zostawiała autora na liście, a jego wpis — także
            // „tylko dla obserwujących" — wychodził na Start. `widoczneDla()`
            // dokłada tu `NOT EXISTS` po `blocks` w obie strony i `EXISTS` po
            // `follows` dla „tylko dla obserwujących": to samo zapytanie, które
            // zwraca treść, sprawdza aktualny stan. Bez dodatkowych zapytań.
            // Własne wpisy widza przechodzi zawsze (autor widzi swoje).
            ->widoczneDla($viewer, bezKorelacji: true)
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
            ->zWidocznymPrzepisemAlboWlasnaTresciBezKorelacji($viewer);
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
}
