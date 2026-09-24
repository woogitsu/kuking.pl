<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Feed tagów — wpisy z tagów, które ta osoba obserwuje (D-021, zastępuje
 * `TopicFeed`).
 *
 * PO CO ISTNIEJE
 * Ten sam powód co przy `TopicFeed`, którego ta klasa zastępuje: nowe konto
 * nikogo nie obserwuje, więc feed obserwowanych jest z definicji pusty.
 * Tagi wybrane w onboardingu (spośród `Tag::scopePromowane()` — lista
 * gospodarza, D-021 „tag promowany") są jedyną rzeczą, którą o kimś wiemy
 * w pierwszej minucie.
 *
 * JEDEN WPIS, NIE WIELE, MIMO WIELU OBSERWOWANYCH TAGÓW (SPEC §1.9)
 * `whereHas('tags', ...)` sprawdza ISTNIENIE dopasowania (`EXISTS`
 * w wygenerowanym SQL), nie robi `JOIN`-a z `post_tags` — wpis z trzema
 * obserwowanymi tagami naraz i tak pojawi się na liście dokładnie raz.
 * Gdyby to było `join('post_tags', ...)`, ten sam wpis wypłynąłby tyle razy,
 * ile ma pasujących tagów — i to byłby dokładnie ten błąd, przed którym
 * SPEC §1.9 ostrzega wprost.
 *
 * CHRONOLOGICZNIE, TAK JAK WSZYSTKO
 * Ta sama decyzja co przy `TopicFeed`/feedzie obserwowanych i z tego samego
 * powodu: ranking zamienia dzielenie się jedzeniem w konkurs (AGENTS.md).
 */
final class TagFeed
{
    /** @return CursorPaginator<int, Post> */
    public function paginate(User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $tagIds = $this->obserwowaneTagi($viewer);

        return Post::query()
            ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
            // Ta sama macierz widoczności co wszędzie indziej: obserwowanie
            // tagu NIE MOŻE być obejściem ustawień prywatności ani blokady.
            ->widoczneDla($viewer)
            // DRUGA BRAMKA, I NIE JEST NADMIAROWA. Wyżej pytamy o widoczność
            // WPISU, a zapowiedź przepisu (issue #368) jest na stałe `public`
            // — widoczność ma trzymać PRZEPIS, nie jego zapowiedź. Bez tego
            // warunku obserwowanie tagu przepuszczało tytuł i zdjęcie główne
            // przepisu „tylko dla obserwujących" osobie, która autora nie
            // obserwuje; karta rysowała jedno i drugie, a pod spodem
            // dopisywała plakietkę „Tylko dla obserwujących".
            //
            // `FollowingFeed`, `DiscoverFeed` i `DailyBoard` mają tę bramkę
            // od issue #368. `TagFeed` był jedynym z czterech strumieni bez
            // niej — i jedynym, do którego wpisy trafiają bez żadnej relacji
            // między widzem a autorem.
            ->zWidocznymPrzepisem($viewer)
            ->tylkoOdAktywnychAutorow()
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Czy jest z czego zbudować ten feed — pyta o TREŚĆ, nie o to, czy
     * człowiek zaznaczył cokolwiek w onboardingu (ten sam powód co
     * `TopicFeed::maTresci()`).
     */
    public function maTresci(User $viewer): bool
    {
        $tagIds = $this->obserwowaneTagi($viewer);

        if ($tagIds === []) {
            return false;
        }

        // DOKŁADNIE TE SAME WARUNKI CO W `paginate()`, łącznie z bramką
        // przepisu. Gdyby ta metoda pytała szerzej, odpowiadałaby „jest co
        // pokazać" o treści, których `paginate()` i tak nie odda — i widz
        // dostałby pusty strumień zamiast ekranu pustego stanu, który mówi,
        // co zrobić dalej.
        return Post::query()
            ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
            ->widoczneDla($viewer)
            ->zWidocznymPrzepisem($viewer)
            ->tylkoOdAktywnychAutorow()
            ->exists();
    }

    /** @return list<string> */
    private function obserwowaneTagi(User $viewer): array
    {
        return $viewer->followedTags()->pluck('tags.id')->all();
    }
}
