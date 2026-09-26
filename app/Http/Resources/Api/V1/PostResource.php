<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Wpis („Co dziś ugotowałeś?") w API (D-272).
 *
 * Zasób NIE DECYDUJE, czy wpis wolno pokazać — to robi `PostPolicy::view`
 * w kontrolerze (albo zapytanie feedu, to samo co na WWW). Zasób pilnuje
 * tylko tego, czego NIE wypuścić: `klucz_wyslania`, statusu moderacji,
 * `hide_as_memory` i przepisu, którego widz nie może zobaczyć.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Post $wpis */
        $wpis = $this->resource;
        $widz = $request->user();
        $przepis = $wpis->recipe;

        $przepisWidoczny = $przepis !== null && Gate::forUser($widz)->allows('view', $przepis);

        return [
            'id' => (string) $wpis->getKey(),
            'kind' => $wpis->kind,
            'title' => $wpis->title,
            'body' => $wpis->body,
            'visibility' => $przepisWidoczny ? $przepis->visibility : $wpis->visibility,
            'published_at' => $wpis->published_at?->toIso8601String(),
            'author' => new AutorResource($wpis->author),
            'photos' => Zdjecie::lista($wpis->relationLoaded('media') ? $wpis->media : []),
            'recipe' => $przepisWidoczny ? [
                'id' => (string) $przepis->getKey(),
                'slug' => $przepis->slug,
                'title' => $przepis->title,
                'hero_photo' => Zdjecie::z($przepis->heroMedia),
            ] : null,
            'tags' => $wpis->relationLoaded('tags')
                ? $wpis->tags->map(fn ($tag) => ['slug' => $tag->slug, 'name' => $tag->name])->values()->all()
                : [],
            'comments_count' => $wpis->comments_count !== null ? (int) $wpis->comments_count : null,
            'url' => $wpis->url(),
        ];
    }
}
