<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Domain\Collections\ZapisyWpisu;
use App\Models\Hide;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * "Świeżo z Kuking" — to, co widzi ktoś, kto nikogo jeszcze nie obserwuje.
 *
 * To NIE jest ranking popularności. To chronologia z rotacją autorów:
 * najpierw najnowszy wpis każdej osoby, potem drugi każdej i tak dalej
 * (issue #1807). Bez tego jedna aktywna osoba zasłania cały serwis, a nowy
 * użytkownik odnosi wrażenie, że "tu jest tylko ta pani".
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
     * Najdalszy wstecz „stan", jaki przyjmujemy z adresu. Kursor starszy niż
     * doba to zakładka, nie przeglądanie — wtedy liczymy od teraz.
     */
    private const STAN_NAJWYZEJ_SEKUND_WSTECZ = 86_400;

    /**
     * @param  string|null  $stan  wartość parametru `stan` z adresu DALSZEJ
     *                             strony (patrz niżej); pierwsza strona podaje `null`
     * @return CursorPaginatorContract<int, Post>
     */
    public function paginate(?User $viewer, ?int $perPage = null, ?string $stan = null): CursorPaginatorContract
    {
        $perPage ??= (int) config('kuking.feed.page_size');
        $chwila = $this->chwilaListy($stan);

        // ROTACJA AUTORÓW (issue #1807, reguła doboru z AGENTS.md §8: „równość
        // autorów"). Najpierw najnowszy wpis każdej osoby, potem drugi każdej,
        // potem trzeci — i tak do końca.
        //
        // Wcześniej stało tu `DISTINCT ON (author_id)` (issue #940): jeden wpis
        // na autora w CAŁEJ sekwencji. Seria jednej osoby przestała zasłaniać
        // innych, ale głębokość Odkrywania równała się liczbie aktywnych
        // autorów — przy dziesięciu osobach dziesięć kart i koniec, a starsze
        // wpisy nigdy nie wracały. Rotacja zachowuje gwarancję z #940 (pierwsza
        // runda to dalej po jednym wpisie od osoby) i oddaje całą resztę.
        //
        // `runda` = `row_number()` w oknie autora, od najnowszego. To liczy
        // WPISY TEJ OSOBY, nie cudze reakcje — nikt nie trafia wyżej za to, ile
        // „Ugotowałem" zebrał (strażnik: `FeedNieSortujePoMierzeReakcjiTest`).
        //
        // WSZYSTKIE BRAMKI WIDOCZNOŚCI SĄ W PODZAPYTANIU, PRZED NUMERACJĄ.
        // Wpis odsiany dopiero na zewnątrz zostawiłby dziurę w rundach autora
        // (jego starszy wpis stałby w rundzie trzeciej zamiast drugiej).
        //
        // `published_at <= chwila` — ta sama chwila na każdej stronie jednego
        // przeglądania. Nowy wpis osoby opublikowany w trakcie przesunąłby
        // wszystkie jej starsze wpisy o rundę dalej, a przesunięty wpis
        // wróciłby na następnej stronie drugi raz. Chwila przechodzi między
        // stronami w parametrze `stan` (dokładany do odnośników niżej).
        $rundy = Post::query()
            ->select('posts.id')
            ->selectRaw('row_number() OVER (PARTITION BY posts.author_id ORDER BY posts.published_at DESC, posts.id DESC) AS runda')
            ->publiclyVisible()
            // Pierwsza strona nie ma granicy (widzi wszystko do teraz), dalsze
            // biorą wpisy do pełnej sekundy chwili pierwszej. Eloquent zapisuje
            // `published_at` z dokładnością do sekundy, więc zdublować się może
            // najwyżej wpis dodany w TEJ SAMEJ sekundzie co pierwsza strona.
            ->when($stan !== null, fn ($query) => $query->where('posts.published_at', '<=', $chwila->format('Y-m-d H:i:sP')))
            // Konto autora musi być w pełni aktywne (audyt A5) — to jest
            // surowszy próg niż w Policy pojedynczego wpisu. Odkrywanie
            // aktywnie POLECA treść nieznajomym, więc zawieszenie (kara
            // czasowa, nie tylko ban) też ma tu wystarczyć do zdjęcia —
            // inaczej strona promowałaby konto będące właśnie pod sankcją.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Punkt zaczepienia dla jawnych poleceń widza (AGENTS.md §8):
            // dziś blokady, od #1810 także ukrycia wpisów i osób.
            ->when($viewer !== null, fn ($query) => $this->bezUkrytychPrzez($query, $viewer))
            // WPIS WSKAZUJĄCY PRZEPIS WYCHODZI TYLKO Z WIDOCZNYM PRZEPISEM
            // (issue #368). Widoczność liczy się Z PRZEPISU, nie z kopii na
            // wpisie — patrz `Post::scopeZWidocznymPrzepisem()`.
            ->zWidocznymPrzepisem($viewer);

        $strona = Post::query()
            ->select('posts.*', 'rotacja.runda')
            ->joinSub($rundy, 'rotacja', 'rotacja.id', '=', 'posts.id')
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
            // Runda, w niej czas, a remis czasu rozstrzyga identyfikator —
            // ta sama trójka, którą kursor zapisuje i odtwarza.
            ->orderBy('rotacja.runda')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            // Pusty napis, nie `null`: na `null` Laravel sięga po kursor z adresu
            // jeszcze raz — ten sam, który właśnie odrzuciliśmy.
            ->cursorPaginate($perPage, ['*'], 'cursor', $this->kursorRotacji() ?? '');

        return $strona->appends('stan', (string) $chwila->getTimestamp());
    }

    /**
     * Kursor z adresu — tylko jeśli opisuje pozycję W ROTACJI.
     *
     * Kursor sprzed rotacji (#940: `published_at` + `id`, np. z zakładki albo
     * z otwartej karty w chwili wdrożenia) nie ma `rotacja.runda`, a Laravel
     * na brakujący parametr rzuca wyjątkiem — czyli 500 na `/odkryj`. Taki
     * kursor nie mówi, gdzie w rundach jesteśmy, więc zaczynamy od początku.
     */
    private function kursorRotacji(): ?Cursor
    {
        $kursor = CursorPaginator::resolveCurrentCursor();

        if ($kursor === null) {
            return null;
        }

        $parametry = $kursor->toArray();
        unset($parametry['_pointsToNextItems']);
        $klucze = array_keys($parametry);
        sort($klucze);

        return $klucze === ['posts.id', 'posts.published_at', 'rotacja.runda'] ? $kursor : null;
    }

    /**
     * Chwila, od której liczymy listę: z `stan` dalszej strony albo teraz.
     *
     * Wartość z adresu jest tylko POZYCJĄ w czasie — nie otwiera niczego, czego
     * widz nie mógłby zobaczyć (bramki widoczności liczą się zawsze od teraz).
     * Przyszłość i wartości starsze niż doba wracają do „teraz".
     */
    private function chwilaListy(?string $stan): CarbonImmutable
    {
        $teraz = CarbonImmutable::now()->startOfSecond();

        if ($stan === null || preg_match('/^\d{1,12}$/', $stan) !== 1) {
            return $teraz;
        }

        $chwila = CarbonImmutable::createFromTimestamp((int) $stan, $teraz->getTimezone());

        if ($chwila->greaterThan($teraz)
            || $chwila->lessThan($teraz->subSeconds(self::STAN_NAJWYZEJ_SEKUND_WSTECZ))) {
            return $teraz;
        }

        return $chwila;
    }

    /**
     * Blokady w obie strony. Zwrotnej („ktoś zablokował mnie") widz nie
     * widzi nigdzie w interfejsie — liczy się tylko do odsiania.
     *
     * @param  Builder<Post>  $query
     */
    private function bezUkrytychPrzez($query, User $viewer): void
    {
        $query->whereNotIn('posts.author_id', $this->hiddenAuthorIdsFor($viewer))
            // Prywatne ukrycia (#1810, D-278): wpis i osoba.
            ->bezUkrytychWpisow($viewer)
            ->bezUkrytychOsob($viewer);
    }

    /**
     * Ile rzeczy widz sam ukrywa: blokady i aktywne ukrycia wpisów i osób (#1810). Pusty stan Odkrywania mówi
     * wtedy „część ukrywasz" i prowadzi do listy, na której da się to cofnąć
     * (AGENTS.md §8: jawne polecenie widza zawsze z listą do cofnięcia).
     *
     * Tylko blokady ZROBIONE PRZEZ widza — blokady, którymi ktoś odciął jego,
     * nie są jego decyzją i pusty stan nie może ich zdradzać.
     */
    public function ileUkrywa(User $viewer): int
    {
        return $viewer->blocking()->count() + Hide::query()->aktywne()->where('user_id', $viewer->getKey())->count();
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
