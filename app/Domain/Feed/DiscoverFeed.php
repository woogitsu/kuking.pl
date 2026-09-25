<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Collections\ZapisyWpisu;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

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
    /**
     * `new ZapisyWpisu` jako domyślna wartość — tak samo jak
     * `LiczbaKukingow` bierze `CookEligibility`. Kontener i tak wstrzyknie
     * tę klasę (nie ma zależności), a domyślna wartość sprawia, że test
     * wołający `new DiscoverFeed` wprost nie musi o niej wiedzieć.
     */
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

    /** @return CursorPaginator<int, Post> */
    public function paginate(?User $viewer, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        // JEDEN WPIS NA AUTORA W CAŁEJ SEKWENCJI, NIE TYLKO NA STRONIE (issue #940).
        //
        // Obietnica z nagłówka tej klasy stała tu od pierwszego commita, ale
        // zapytanie jej nie wykonywało: jedna osoba z serią wpisów wypełniała
        // całą pierwszą stronę i landing gościa. Reguła jest własnością
        // zapytania — `DISTINCT ON (author_id)` w podzapytaniu, tym samym
        // wzorcem co `DailyBoard::automaticPosts()`.
        //
        // „W widoku" = w całym odkrywaniu, nie w jednej stronie: wybór
        // reprezentanta nie zależy od kursora, więc druga strona nie powtórzy
        // autora z pierwszej i nie zgubi pozostałych. Kolejność reprezentantów
        // jest dalej czysto chronologiczna — to nie jest ranking.
        //
        // WSZYSTKIE BRAMKI WIDOCZNOŚCI SĄ W PODZAPYTANIU, PRZED WYBOREM
        // reprezentanta. Wpis odsiany dopiero na zewnątrz zabrałby ze sobą
        // całe miejsce autora, zamiast oddać je jego starszemu dozwolonemu
        // wpisowi. `ORDER BY` musi zaczynać się od `author_id` (wymóg
        // Postgresa dla `DISTINCT ON`); `published_at, id` wybierają najnowszy.
        $najnowszyKazdegoAutora = Post::query()
            ->selectRaw('DISTINCT ON (posts.author_id) posts.id')
            ->publiclyVisible()
            // Konto autora musi być w pełni aktywne (audyt A5) — to jest
            // surowszy próg niż w Policy pojedynczego wpisu. Odkrywanie
            // aktywnie POLECA treść nieznajomym, więc zawieszenie (kara
            // czasowa, nie tylko ban) też ma tu wystarczyć do zdjęcia —
            // inaczej strona promowałaby konto będące właśnie pod sankcją.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->when($viewer !== null, fn ($query) => $query->whereNotIn(
                'author_id',
                $this->hiddenAuthorIdsFor($viewer),
            ))
            // WPIS WSKAZUJĄCY PRZEPIS WYCHODZI TYLKO Z WIDOCZNYM PRZEPISEM
            // (issue #368). Widoczność liczy się Z PRZEPISU, nie z kopii na
            // wpisie — patrz `Post::scopeZWidocznymPrzepisem()`.
            ->zWidocznymPrzepisem($viewer)
            ->orderBy('posts.author_id')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id');

        return Post::query()
            ->whereIn('posts.id', $najnowszyKazdegoAutora)
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
                // Patrz komentarz w FollowingFeed::paginate() — karta wpisu
                // pokazuje tematy TYLKO wtedy, gdy relacja jest już
                // doładowana, więc bez tego wpisy na „Świeżo z Kuking"
                // nie miałyby żadnych chipów tematów.
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
