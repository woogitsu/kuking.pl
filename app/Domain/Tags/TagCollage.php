<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Najnowsze publiczne zdjęcia, najwyżej jedno od osoby na tag. */
final class TagCollage
{
    private const LIMIT = 5;

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
            ->whereIn('post_tags.tag_id', array_keys($result))
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
        $rows = DB::query()->fromSub($ranked, 'ranked')
            ->where('tag_row', '<=', self::LIMIT)
            ->orderBy('tag_id')->orderBy('tag_row')->get();

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
