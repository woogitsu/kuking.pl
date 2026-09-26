<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
 *  3. na obu częściach BRAMKI I BLOKADY (publiczny, opublikowany, aktywne
 *     konto autora, widoczny przepis, blokady w obie strony) oraz UKRYCIA
 *     widza z #1810 (wpis i osoba) — ZANIM cokolwiek zostanie wybrane;
 *  4. najwyżej jeden przepis od osoby na całej półce.
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
     * „Dlaczego to widzę" — cała reguła doboru jednym zdaniem, pokazywana
     * na półce. Zmiana reguły = zmiana tego zdania, D-304 i strażnika.
     */
    public const DLACZEGO = 'Pokazujemy najnowsze przepisy z tagów, które obserwujesz, i z jednego tagu polecanego przez gospodarza — po jednym od osoby, bez tego, co ukrywasz, i nigdy według liczby polubień ani Twoich kliknięć.';

    /**
     * @return array{
     *     z_tagow: list<array{post: Post, tag: Tag}>,
     *     od_gospodarza: array{tag: Tag, wpisy: list<Post>}|null,
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

        return [
            'z_tagow' => $zTagow,
            'od_gospodarza' => $this->tematOdGospodarza(
                $widz,
                $tagIds,
                array_map(fn (array $p) => $p['post']->author_id, $zTagow),
            ),
            'obserwuje_tagi' => $tagIds !== [],
        ];
    }

    /**
     * Pierwszy tag z listy gospodarza (jego kolejność), którego widz nie
     * obserwuje i w którym jest choć jeden przepis do pokazania.
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
            ->get();

        foreach ($promowane as $tag) {
            $wpisy = $this->najnowszyOdKazdejOsoby(
                fn (Builder $q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tag->getKey())),
                $widz,
                self::NA_POLCE_OD_GOSPODARZA,
                $zajeciAutorzy,
            );

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
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            ->limit($ile)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, $widz);

        return array_values($wpisy->filter(fn (Post $p) => $p->recipe !== null)->all());
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
