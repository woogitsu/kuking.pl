<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/** Publiczne zdjęcia i ich autorzy, niezależnie od zalogowanego widza. */
final class TagPublicStats
{
    /**
     * @param  iterable<string>  $tagIds
     * @return array<string, array{photosCount: int, contributorsCount: int}>
     */
    public function forTags(iterable $tagIds): array
    {
        $result = [];
        foreach ($tagIds as $id) {
            $result[$id] = ['photosCount' => 0, 'contributorsCount' => 0];
        }

        if ($result === []) {
            return [];
        }

        // Podzapytanie zachowuje zakresy modeli i SoftDeletes, a status
        // wpisu nie staje się niejednoznaczny po dołączeniu tabeli media.
        $posts = Post::query()->publiclyVisible()
            ->tylkoOdAktywnychAutorow()->zWidocznymPrzepisem(null)
            ->select(['posts.id', 'posts.author_id']);

        $rows = DB::query()->fromSub($posts, 'visible_posts')
            ->join('post_tags', 'post_tags.post_id', '=', 'visible_posts.id')
            ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
            ->join('post_media', 'post_media.post_id', '=', 'visible_posts.id')
            ->join('media', 'media.id', '=', 'post_media.media_id')
            ->whereIn('post_tags.tag_id', array_keys($result))
            ->where('tags.status', Tag::STATUS_ACTIVE)
            ->where('media.status', Media::STATUS_READY)
            ->groupBy('post_tags.tag_id')
            ->selectRaw('post_tags.tag_id, COUNT(DISTINCT media.id) AS photos_count, COUNT(DISTINCT visible_posts.author_id) AS contributors_count')
            ->get();

        foreach ($rows as $row) {
            $result[$row->tag_id] = [
                'photosCount' => (int) $row->photos_count,
                'contributorsCount' => (int) $row->contributors_count,
            ];
        }

        return $result;
    }
}
