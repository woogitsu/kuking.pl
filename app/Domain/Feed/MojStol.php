<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * „Mój stół" — dobrowolna, prywatna półka przepisów (issue #1749, D-304).
 *
 * CO TO JEST, A CZYM NIE JEST
 * Półka pomaga znaleźć następne danie do ugotowania. NIE jest feedem
 * rekomendacyjnym: nie zmienia Obserwowanych ani „Świeżo z Kuking", nie ma
 * wyniku dopasowania, wag ani modelu. Dobiera WYŁĄCZNIE regułami z zamkniętej
 * listy AGENTS.md §8 (D-275):
 *
 *  1. „Z Twoich tagów" — JAWNE POLECENIE WIDZA (obserwowane tagi), w nim
 *     KOLEJNOŚĆ PO CZASIE i RÓWNOŚĆ AUTORÓW (najnowszy przepis każdej osoby,
 *     potem od najnowszego, najwyżej `NA_POLCE_Z_TAGOW`);
 *  2. „Temat od gospodarza" — OZNACZONY WYBÓR GOSPODARZA (tag z listy
 *     `tag_promotions`, w kolejności gospodarza), którego widz jeszcze nie
 *     obserwuje; w nim znów czas i równość autorów. To jest obowiązkowa pula
 *     „nowego tematu" z issue — przeciw bańce — i jednocześnie zimny start
 *     dla kogoś, kto nie obserwuje żadnego tagu;
 *  3. „kuKINGi na dziś" — OZNACZONY WYBÓR GOSPODARZA na dziś (`daily_picks`,
 *     wpisy wskazujące przepis), w kolejności ustawionej przez gospodarza
 *     (`daily_picks.position`) — decyzja właściciela z 26.09 (PR #1875);
 *  4. na wszystkich częściach BRAMKI I BLOKADY (publiczny, opublikowany, aktywne
 *     konto autora, widoczny przepis, blokady w obie strony) oraz UKRYCIA
 *     widza z #1810 (wpis i osoba) — ZANIM cokolwiek zostanie wybrane;
 *  5. najwyżej jeden przepis od osoby na całej półce — część późniejsza
 *     pomija autorów, którzy już stoją na półce.
 *
 * CZEGO TU NIE MA I NIE WOLNO DOPISAĆ BEZ DECYZJI WŁAŚCICIELA
 * Liczby „Ugotowałem", reakcji, zapisów, komentarzy, odsłon — ani do wyboru,
 * ani do kolejności. Niczego, co widz kliknął, zapisał albo ugotował: to byłoby
 * przewidywanie gustu z zachowania. „Podobne składniki" z issue też odpadają
 * — to dopasowanie do tego, co widz ugotował. Strażnik:
 * `tests/Feature/FeedNieSortujePoMierzeReakcjiTest.php` (skan tego pliku
 * i kotwica) oraz kontrola ujemna w `scripts/kontrole-negatywne-alfa08.py`.
 *
 * Nie ma czego resetować: półka nic nie zapamiętuje. Zmienia się, gdy widz
 * zmieni obserwowane tagi albo ukrycia — obie listy mają własne „cofnij".
 */
final class MojStol
{
    /** Najwięcej przepisów z obserwowanych tagów na półce. */
    public const NA_POLCE_Z_TAGOW = 6;

    /** Najwięcej przepisów z tematu od gospodarza. */
    public const NA_POLCE_OD_GOSPODARZA = 3;

    /**
     * Najwięcej tagów z listy gospodarza rozpatrywanych przy jednym wejściu
     * (#1968). Lista `tag_promotions` nie ma górnej granicy, a koszt wejścia
     * na półkę nie może rosnąć razem z nią. Dalsze tagi po prostu nie
     * trafiają do puli — kolejność gospodarza decyduje, które się mieszczą.
     */
    public const TEMATOW_DO_ROZPATRZENIA = 20;

    /** Najwięcej przepisów z „kuKINGów na dziś". */
    public const NA_POLCE_NA_DZIS = 3;

    /**
     * „Dlaczego to widzę" — cała reguła doboru jednym zdaniem, pokazywana
     * na półce. Zmiana reguły = zmiana tego zdania, D-304 i strażnika.
     */
    public const DLACZEGO = 'Pokazujemy najnowsze przepisy z tagów, które obserwujesz, z jednego tagu polecanego przez gospodarza i przepisy, które gospodarz wybrał na dziś — po jednym od osoby, bez tego, co ukrywasz, i nigdy według liczby polubień ani Twoich kliknięć.';

    /**
     * @return array{
     *     z_tagow: list<array{post: Post, tag: Tag}>,
     *     od_gospodarza: array{tag: Tag, wpisy: list<Post>}|null,
     *     na_dzis: list<Post>,
     *     obserwuje_tagi: bool
     * }
     */
    public function dlaWidza(User $widz): array
    {
        $tagi = $widz->followedTags()->where('tags.status', Tag::STATUS_ACTIVE)->get();
        $tagIds = $tagi->modelKeys();

        $zTagow = [];

        if ($tagIds !== []) {
            $wpisy = $this->najnowszyOdKazdejOsoby(
                fn (Builder $q) => $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $tagIds)),
                $widz,
                self::NA_POLCE_Z_TAGOW,
                [],
            );

            foreach ($wpisy as $post) {
                // Powód przy pozycji: pierwszy obserwowany tag wpisu w kolejności
                // tagów wpisu — ten sam sposób co podpis „Z tagu: …" na Starcie.
                $tag = $post->tags->first(fn (Tag $t) => $t->status === Tag::STATUS_ACTIVE
                    && in_array($t->getKey(), $tagIds, true));

                if ($tag !== null) {
                    $zTagow[] = ['post' => $post, 'tag' => $tag];
                }
            }
        }

        $zajeci = array_map(fn (array $p) => $p['post']->author_id, $zTagow);
        $odGospodarza = $this->tematOdGospodarza($widz, $tagIds, $zajeci);

        foreach ($odGospodarza['wpisy'] ?? [] as $post) {
            $zajeci[] = $post->author_id;
        }

        return [
            'z_tagow' => $zTagow,
            'od_gospodarza' => $odGospodarza,
            'na_dzis' => $this->naDzis($widz, $zajeci),
            'obserwuje_tagi' => $tagIds !== [],
        ];
    }

    /**
     * „kuKINGi na dziś" — przepisy, które gospodarz wybrał na dziś, w JEGO
     * kolejności (`daily_picks.position`, remis rozstrzyga identyfikator
     * wyboru). Te same bramki co reszta półki — także ukrycie osoby, bo na
     * półce właściciel chce jednego zestawu filtrów (inaczej niż na tablicy,
     * gdzie ukrycie osoby wyboru nie zdejmuje, D-278). Gospodarz mógł wybrać
     * dwa przepisy jednej osoby — na półkę idzie pierwszy w jego kolejności.
     *
     * @param  list<string>  $zajeciAutorzy
     * @return list<Post>
     */
    private function naDzis(User $widz, array $zajeciAutorzy): array
    {
        // „Po jednym od osoby" i limit stawia baza (#1968): lista wyborów
        // gospodarza na dziś nie ma górnej granicy, więc wejście na półkę nie
        // może jej pobierać w całości. Bramki stoją PRZED numeracją.
        $poOsobie = Post::query()
            ->select('posts.id', 'daily_picks.id as wybor_id')
            ->selectRaw('row_number() OVER (PARTITION BY posts.author_id ORDER BY daily_picks.position, daily_picks.id) AS kolejny_wybor_osoby')
            ->join('daily_picks', function ($join): void {
                $join->on('daily_picks.subject_id', '=', 'posts.id')
                    ->where('daily_picks.subject_type', DailyPick::TYPE_POST)
                    ->whereDate('daily_picks.shown_on', Czas::dzisiajData());
            })
            ->tap(fn (Builder $q) => $this->bramki($q, $widz))
            ->when($zajeciAutorzy !== [], fn ($q) => $q->whereNotIn('posts.author_id', $zajeciAutorzy));

        /** @var Collection<int, Post> $wpisy */
        $wpisy = Post::query()
            ->select('posts.*')
            ->joinSub($poOsobie, 'wybor', 'wybor.id', '=', 'posts.id')
            ->where('wybor.kolejny_wybor_osoby', 1)
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
            ])
            // Kolejność gospodarza z tego samego wyboru, który wygrał numerację.
            ->join('daily_picks', 'daily_picks.id', '=', 'wybor.wybor_id')
            ->orderBy('daily_picks.position')
            ->orderBy('daily_picks.id')
            ->limit(self::NA_POLCE_NA_DZIS)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, $widz);

        return array_values($wpisy->filter(fn (Post $p) => $p->recipe !== null)->all());
    }

    /**
     * Pierwszy tag z listy gospodarza (jego kolejność), którego widz nie
     * obserwuje i w którym jest choć jeden przepis do pokazania.
     *
     * STAŁA LICZBA ZAPYTAŃ (#1968). Wcześniej każdy promowany tag bez
     * przepisu dla widza kosztował osobne zapytanie z bramkami i oknem —
     * koszt wejścia rósł z długością listy gospodarza. Teraz: jedno
     * zapytanie po listę (najwyżej `TEMATOW_DO_ROZPATRZENIA` tagów) i jedno
     * zbiorcze po kandydatów ze WSZYSTKICH tych tagów naraz — w każdym tagu
     * najnowszy przepis każdej osoby, potem najwyżej `NA_POLCE_OD_GOSPODARZA`
     * od najnowszego. Reguły są te same co w pętli: bramki, blokady
     * i ukrycia przed numeracją, pominięcie autorów już stojących na półce.
     * Wybór tematu (pierwszy w kolejności gospodarza, który ma co pokazać)
     * zapada w PHP na tej ograniczonej puli.
     *
     * @param  list<string>  $obserwowane
     * @param  list<string>  $zajeciAutorzy  autorzy już stojący na półce
     * @return array{tag: Tag, wpisy: list<Post>}|null
     */
    private function tematOdGospodarza(User $widz, array $obserwowane, array $zajeciAutorzy): ?array
    {
        $promowane = Tag::query()
            ->promowane()
            ->when($obserwowane !== [], fn ($q) => $q->whereNotIn('tags.id', $obserwowane))
            ->limit(self::TEMATOW_DO_ROZPATRZENIA)
            ->get();

        if ($promowane->isEmpty()) {
            return null;
        }

        // Numeracja w oknie (temat, osoba): najnowszy wpis każdej osoby
        // W DANYM TAGU. Bramki stoją tu, PRZED numeracją — jak w
        // `najnowszyOdKazdejOsoby()`.
        $poOsobie = Post::query()
            ->join('post_tags as temat_tagi', 'temat_tagi.post_id', '=', 'posts.id')
            ->whereIn('temat_tagi.tag_id', $promowane->modelKeys())
            ->select('posts.id', 'posts.published_at', 'temat_tagi.tag_id as temat_id')
            ->selectRaw('row_number() OVER (PARTITION BY temat_tagi.tag_id, posts.author_id ORDER BY posts.published_at DESC, posts.id DESC) AS kolejny_wpis_osoby')
            ->tap(fn (Builder $q) => $this->bramki($q, $widz))
            ->when($zajeciAutorzy !== [], fn ($q) => $q->whereNotIn('posts.author_id', $zajeciAutorzy));

        // Druga numeracja: miejsce osoby w temacie, od najnowszego — limit
        // `NA_POLCE_OD_GOSPODARZA` na temat stawia baza, nie PHP.
        $wTemacie = DB::query()
            ->fromSub($poOsobie, 'po_osobie')
            ->select('po_osobie.id', 'po_osobie.temat_id')
            ->selectRaw('row_number() OVER (PARTITION BY po_osobie.temat_id ORDER BY po_osobie.published_at DESC, po_osobie.id DESC) AS miejsce_w_temacie')
            ->where('po_osobie.kolejny_wpis_osoby', 1);

        /** @var Collection<int, Post> $kandydaci */
        $kandydaci = Post::query()
            ->select('posts.*', 'w_temacie.temat_id')
            ->joinSub($wTemacie, 'w_temacie', 'w_temacie.id', '=', 'posts.id')
            ->where('w_temacie.miejsce_w_temacie', '<=', self::NA_POLCE_OD_GOSPODARZA)
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
                'tags:id,slug,name,status',
            ])
            ->withVisibleCommentCount($widz)
            ->tap(fn (Builder $q) => $this->odNajnowszego($q))
            ->get();

        Post::ukryjNiedostepnePrzepisy($kandydaci, $widz);

        $wedlugTematu = [];

        foreach ($kandydaci as $post) {
            $temat = (string) $post->getAttribute('temat_id');
            // Kolumna pomocnicza zapytania — nie zostaje w modelu wpisu.
            unset($post->temat_id);

            if ($post->recipe !== null) {
                $wedlugTematu[$temat][] = $post;
            }
        }

        foreach ($promowane as $tag) {
            $wpisy = $wedlugTematu[(string) $tag->getKey()] ?? [];

            if ($wpisy !== []) {
                return ['tag' => $tag, 'wpisy' => $wpisy];
            }
        }

        return null;
    }

    /**
     * Najnowszy przepis każdej osoby, potem od najnowszego — ucięte do `$ile`.
     *
     * `kolejny_wpis_osoby` = `row_number()` w oknie autora, od najnowszego.
     * Liczy WPISY TEJ OSOBY, nie cudze reakcje — ta sama technika co rotacja
     * w `DiscoverFeed`. Wszystkie bramki stoją w podzapytaniu, PRZED numeracją:
     * wpis odsiany dopiero na zewnątrz zabrałby osobie miejsce na półce.
     *
     * @param  \Closure(Builder<Post>): mixed  $zrodlo
     * @param  list<string>  $pominAutorow
     * @return list<Post>
     */
    private function najnowszyOdKazdejOsoby(\Closure $zrodlo, User $widz, int $ile, array $pominAutorow): array
    {
        $numerowane = Post::query()
            ->select('posts.id')
            ->selectRaw('row_number() OVER (PARTITION BY posts.author_id ORDER BY posts.published_at DESC, posts.id DESC) AS kolejny_wpis_osoby')
            ->tap(fn (Builder $q) => $this->bramki($q, $widz))
            ->tap($zrodlo)
            ->when($pominAutorow !== [], fn ($q) => $q->whereNotIn('posts.author_id', $pominAutorow));

        /** @var Collection<int, Post> $wpisy */
        $wpisy = Post::query()
            ->select('posts.*')
            ->joinSub($numerowane, 'po_osobie', 'po_osobie.id', '=', 'posts.id')
            ->where('po_osobie.kolejny_wpis_osoby', 1)
            ->with([
                'author.profile.avatar',
                'media',
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
                'tags:id,slug,name,status',
            ])
            ->withVisibleCommentCount($widz)
            ->tap(fn (Builder $q) => $this->odNajnowszego($q))
            ->limit($ile)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, $widz);

        return array_values($wpisy->filter(fn (Post $p) => $p->recipe !== null)->all());
    }

    /**
     * Kolejność po czasie publikacji — jedno miejsce dla obu sekcji
     * z tagów (strażnik `FeedNieSortujePoMierzeReakcjiTest` i jego kontrola
     * ujemna podmieniają dokładnie tę linię).
     *
     * @param  Builder<Post>  $q
     */
    private function odNajnowszego(Builder $q): void
    {
        $q
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id');
    }

    /**
     * Bramki, blokady i ukrycia — przed wyborem, nie po nim.
     *
     * @param  Builder<Post>  $q
     */
    private function bramki(Builder $q, User $widz): void
    {
        $q->publiclyVisible()
            // Półka przepisów: wpis musi wskazywać przepis, który widz może
            // dziś zobaczyć (issue #368 — widoczność liczy się z przepisu).
            ->whereNotNull('posts.recipe_id')
            ->zWidocznymPrzepisem($widz)
            // Serwis sam podsuwa — więc wąski próg „tylko aktywne konta",
            // jak Odkrywanie (audyt A5).
            ->tylkoOdAktywnychAutorow()
            // Blokady w obie strony.
            ->widoczneDla($widz)
            // Jawne polecenia widza „mniej" (#1810, D-278): ukryty wpis
            // i ukryta osoba znikają — półka jest podsunięciem, jak Odkrywanie.
            ->bezUkrytychWpisow($widz)
            ->bezUkrytychOsob($widz)
            // Własne przepisy widz ma w zeszycie i na profilu.
            ->where('posts.author_id', '!=', $widz->getKey());
    }
}
