<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Collections\ZapisyWpisu;
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
    /**
     * `new ZapisyWpisu` jako domyślna wartość — tak samo jak
     * `LiczbaKukingow` bierze `CookEligibility`. Kontener i tak wstrzyknie
     * tę klasę (nie ma zależności), a domyślna wartość sprawia, że test
     * wołający `new DiscoverFeed` wprost nie musi o niej wiedzieć.
     */
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

    /**
     * `$zWlasnymi` — tylko dla Startu osoby, która nikogo nie obserwuje
     * (issue #1318, decyzja właściciela z 24.09). Wtedy to jest feed
     * zastępczy JEJ strony głównej, a `FollowingFeed` celowo pokazuje
     * własne wpisy, żeby po publikacji nie było wrażenia, że nic się nie
     * zapisało. Bez tego własny wpis „tylko dla obserwujących" nie
     * pojawiał się na Starcie w ogóle.
     *
     * JEDNO ZAPYTANIE Z `OR`, NIE DWA SKLEJANE W PHP: wpis albo spełnia
     * warunek, albo nie — więc własny wpis publiczny nie wyjdzie dwa razy,
     * kolejność zostaje chronologiczna, a kursor działa jak dotąd.
     *
     * `/discover` i strona dla gości wołają bez tej flagi: tam to jest
     * „Świeżo z Kuking" dla wszystkich, a wpis „tylko dla obserwujących"
     * nie ma prawa wyjść poza autora i obserwujących.
     *
     * @return CursorPaginator<int, Post>
     */
    public function paginate(?User $viewer, ?int $perPage = null, bool $zWlasnymi = false): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $wlasne = $zWlasnymi && $viewer !== null;

        return Post::query()
            ->when(! $wlasne, fn ($query) => $query->publiclyVisible())
            ->when($wlasne, fn ($query) => $query
                ->enabledKinds()
                ->published()
                ->where(fn ($widocznosc) => $widocznosc
                    ->where('visibility', Post::VISIBILITY_PUBLIC)
                    // Te same dwie widoczności, które `FollowingFeed` bierze
                    // z własnych wpisów — prywatne zostają w archiwum autora.
                    ->orWhere(fn ($moje) => $moje
                        ->where('author_id', $viewer->getKey())
                        ->where('visibility', Post::VISIBILITY_FOLLOWERS))))
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
