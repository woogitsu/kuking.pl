<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Najnowsze publiczne zdjęcia, najwyżej jedno od osoby na tag.
 *
 * WERSJA DLA GOŚCIA W CACHE (audyt B4 W2). Dobór kafli to trzy warstwy
 * `ROW_NUMBER()` po złączeniu wszystkich publicznych wpisów tagu ze
 * zdjęciami — liczone przy każdej odsłonie `/tagi` dla stu tagów, także
 * dla robotów. Dla widza anonimowego wynik jest ten sam dla każdego, więc
 * `forTagsWCache()` trzyma w cache WIERSZE doboru (tag, wpis, zdjęcie) per tag przez
 * `CACHE_SEKUND`. Zdjęcia i wpisy-rodziców dociągamy zawsze świeżo, z tymi
 * samymi bramkami (`visiblePosts()`, status zdjęcia) — kafel schowany po
 * zapisaniu cache po prostu wypada. Zalogowany widz ma blokady, więc liczy
 * się jak dotąd.
 */
final class TagCollage
{
    private const LIMIT = 5;

    public const CACHE_SEKUND = 600;

    private const KLUCZ = 'tagi:kolaz-goscia:';

    /**
     * Każde medium ma posts z JEDNYM wybranym rodzicem i jego author.profile.
     * Nie wczytujemy innych, potencjalnie prywatnych rodziców tego zdjęcia.
     *
     * @param  iterable<string>  $tagIds
     * @return array<string, Collection<int, Media>>
     */
    public function forTags(iterable $tagIds, ?User $viewer = null): array
    {
        $result = [];
        foreach ($tagIds as $id) {
            $result[$id] = new Collection;
        }
        if ($result === []) {
            return [];
        }

        return $this->kafle($result, $this->wiersze(array_keys($result), $viewer), $viewer);
    }

    /**
     * Unieważnia zapisany dobór gościa dla podanych tagów. Strony tego nie
     * wołają (świeżość do `CACHE_SEKUND` to świadoma cena); służy
     * przyrządom, które piszą do bazy z pominięciem aplikacji i od razu
     * mierzą wynik (np. `scripts/fixtures/kompozycje-515.php`).
     *
     * @param  iterable<string>  $tagIds
     */
    public static function zapomnijGoscia(iterable $tagIds): void
    {
        foreach ($tagIds as $id) {
            Cache::forget(self::KLUCZ.$id);
        }
    }

    /**
     * To samo co `forTags()`, ale dobór dla GOŚCIA z cache (komentarz klasy).
     * Tego używają strony; `forTags()` zostaje dokładne.
     *
     * @param  iterable<string>  $tagIds
     * @return array<string, Collection<int, Media>>
     */
    public function forTagsWCache(iterable $tagIds, ?User $viewer = null): array
    {
        if ($viewer !== null) {
            return $this->forTags($tagIds, $viewer);
        }

        $result = [];
        foreach ($tagIds as $id) {
            $result[$id] = new Collection;
        }
        if ($result === []) {
            return [];
        }

        return $this->kafle($result, $this->wierszeGoscia(array_keys($result)), null);
    }

    /**
     * @param  array<string, Collection<int, Media>>  $result
     * @param  SupportCollection<int, object{tag_id: string, post_id: string, media_id: string}>  $rows
     * @return array<string, Collection<int, Media>>
     */
    private function kafle(array $result, SupportCollection $rows, ?User $viewer): array
    {

        if ($rows->isEmpty()) {
            return $result;
        }

        $media = Media::query()->whereKey($rows->pluck('media_id')->unique()->all())
            ->where('status', Media::STATUS_READY)->get()->keyBy('id');
        $parents = $this->visiblePosts($viewer)
            ->whereKey($rows->pluck('post_id')->unique()->all())
            ->with('author.profile')->get()->keyBy('id');

        foreach ($rows as $row) {
            $photo = $media->get($row->media_id);
            $parent = $parents->get($row->post_id);
            if ($photo === null || $parent === null) {
                continue;
            }
            // W różnych tagach to samo medium może mieć innego rodzica.
            $tile = clone $photo;
            $tile->setRelation('posts', new Collection([$parent]));
            $result[$row->tag_id]->push($tile);
        }

        return $result;
    }

    /**
     * Wiersze doboru dla gościa: z cache per tag, brakujące liczone razem.
     *
     * @param  list<string>  $tagIds
     * @return SupportCollection<int, object{tag_id: string, post_id: string, media_id: string}>
     */
    private function wierszeGoscia(array $tagIds): SupportCollection
    {
        $zCache = Cache::many(array_map(fn (string $id): string => self::KLUCZ.$id, $tagIds));

        $brakujace = array_values(array_filter(
            $tagIds,
            fn (string $id): bool => ! is_array($zCache[self::KLUCZ.$id] ?? null),
        ));

        if ($brakujace !== []) {
            $policzone = array_fill_keys($brakujace, []);
            foreach ($this->wiersze($brakujace, null) as $row) {
                $policzone[$row->tag_id][] = ['tag_id' => $row->tag_id, 'post_id' => $row->post_id, 'media_id' => $row->media_id];
            }

            $doZapisu = [];
            foreach ($policzone as $id => $wiersze) {
                $doZapisu[self::KLUCZ.$id] = $wiersze;
                $zCache[self::KLUCZ.$id] = $wiersze;
            }
            Cache::putMany($doZapisu, self::CACHE_SEKUND);
        }

        $rows = new SupportCollection;
        foreach ($tagIds as $id) {
            foreach ($zCache[self::KLUCZ.$id] as $wiersz) {
                $rows->push((object) $wiersz);
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $tagIds
     * @return SupportCollection<int, object{tag_id: string, post_id: string, media_id: string}>
     */
    private function wiersze(array $tagIds, ?User $viewer): SupportCollection
    {
        $posts = $this->visiblePosts($viewer)
            ->select(['posts.id', 'posts.author_id', 'posts.published_at']);

        // Pełny porządek rozstrzyga również remisy dat i kilka zdjęć wpisu.
        // Deduplikacja PRZED limitem: zapas 40 wpisów jednego autora nie
        // może zasłonić starszych zdjęć pozostałych osób.
        $order = 'published_at DESC, post_id DESC, media_created_at DESC, position ASC, media_id DESC';
        $candidates = DB::query()->fromSub($posts, 'visible_posts')
            ->join('post_tags', 'post_tags.post_id', '=', 'visible_posts.id')
            ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
            ->join('post_media', 'post_media.post_id', '=', 'visible_posts.id')
            ->join('media', 'media.id', '=', 'post_media.media_id')
            ->whereIn('post_tags.tag_id', $tagIds)
            ->where('tags.status', Tag::STATUS_ACTIVE)
            ->where('media.status', Media::STATUS_READY)
            ->selectRaw('post_tags.tag_id, visible_posts.id AS post_id, visible_posts.author_id, visible_posts.published_at, media.id AS media_id, media.created_at AS media_created_at, post_media.position');

        // To samo medium może być przypięte do kilku wpisów; jego najnowsza
        // dostępna publikacja wyznacza autora i rodzica tego kafla.
        $uniqueMedia = DB::query()->fromSub($candidates, 'candidates')
            ->selectRaw('candidates.*, ROW_NUMBER() OVER (PARTITION BY tag_id, media_id ORDER BY '.$order.') AS media_row');
        $authors = DB::query()->fromSub($uniqueMedia, 'unique_media')
            ->where('media_row', 1)
            ->selectRaw('unique_media.*, ROW_NUMBER() OVER (PARTITION BY tag_id, author_id ORDER BY '.$order.') AS author_row');
        $ranked = DB::query()->fromSub($authors, 'authors')
            ->where('author_row', 1)
            ->selectRaw('authors.*, ROW_NUMBER() OVER (PARTITION BY tag_id ORDER BY '.$order.') AS tag_row');

        return DB::query()->fromSub($ranked, 'ranked')
            ->where('tag_row', '<=', self::LIMIT)
            ->orderBy('tag_id')->orderBy('tag_row')
            ->get(['tag_id', 'post_id', 'media_id']);

    }

    /** @return Builder<Post> */
    private function visiblePosts(?User $viewer): Builder
    {
        $query = Post::query()->publiclyVisible()->tylkoOdAktywnychAutorow()
            ->zWidocznymPrzepisem(null);

        // Kolaż pokazuje treść, nie anonimowy agregat #369. Publiczność
        // pozostaje wymagana także właścicielowi; blokady tylko zawężają.
        if ($viewer !== null) {
            $query->widoczneDla($viewer)->zWidocznymPrzepisem($viewer);
        }

        return $query;
    }
}
