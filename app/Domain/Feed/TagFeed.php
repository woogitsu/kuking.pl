<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Collections\ZapisyWpisu;
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
    /**
     * `new ZapisyWpisu` jako domyślna wartość — tak samo jak
     * `LiczbaKukingow` bierze `CookEligibility`. Kontener i tak wstrzyknie
     * tę klasę (nie ma zależności), a domyślna wartość sprawia, że test
     * wołający `new TagFeed` wprost nie musi o niej wiedzieć.
     */
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

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
            ->tylkoOdAktywnychAutorow()
            ->with([
                'author.profile.avatar',
                'media',
                // `visibility` i `hero_media_id` W SELEKCIE, a `heroMedia`
                // doładowane — dokładnie jak w `FollowingFeed`, `DiscoverFeed`
                // i `DailyBoard` (issue #368). Ten feed jako jedyny z czterech
                // został przy samym `recipe:id,title,slug`, a karta wpisu
                // (`post-card.blade.php`) czyta z tej relacji OBIE brakujące
                // kolumny: `visibility` na plakietkę widoczności i
                // `hero_media_id` na zdjęcie przepisu.
                //
                // Kolumna pominięta w selekcie NIE JEST BŁĘDEM — wraca `null`.
                // Skutek był więc podwójnie cichy: `heroMedia` bez klucza
                // obcego oddawało `null`, czyli wpis wskazujący przepis stał
                // w strumieniu bez zdjęcia, a `visibility` jako `null` schodziło
                // przez `?? $post->visibility` do widoczności WPISU — a ta przy
                // wpisie wskazującym przepis jest na stałe `public`. Karta
                // pisała więc autorowi „publicznie" pod przepisem widocznym
                // tylko dla obserwujących.
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
                'tags:id,slug,name',
            ])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            // Liczba zapisów i stan „mam to w zeszycie" — TYM SAMYM
            // zapytaniem, co wszystko powyżej (issue #275, D-081). Reguły
            // (kto się liczy, od ilu osób widać liczbę) siedzą w
            // `ZapisyWpisu`; tutaj jest tylko miejsce, w którym dokładamy
            // kolumnę do SELECT-a. Bez tego karta wpisu nie pokazałaby ani
            // liczby, ani potwierdzenia — dokładnie jak z `tags:id,slug,name`
            // wyżej.
            ->tap(fn ($q) => $this->zapisy->dolicz($q, $viewer))
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

        return Post::query()
            ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
            ->widoczneDla($viewer)
            ->tylkoOdAktywnychAutorow()
            ->exists();
    }

    /** @return list<string> */
    private function obserwowaneTagi(User $viewer): array
    {
        return $viewer->followedTags()->pluck('tags.id')->all();
    }
}
