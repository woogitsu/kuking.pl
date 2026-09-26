<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Komentarz w API (D-272). Treść komentarza zdjętego zostaje pusta
 * (`body: null`, `removed: true`) — ten sam ślad co na WWW, żeby rozmowa
 * pod nim nie traciła sensu. Odpowiedzi przychodzą już przefiltrowane przez
 * `Comment::scopeWidoczneDla()` (blokady w obie strony).
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Comment $komentarz */
        $komentarz = $this->resource;
        $zdjety = $komentarz->body_removed_at !== null;

        return [
            'id' => (string) $komentarz->getKey(),
            'body' => $zdjety ? null : $komentarz->body,
            'removed' => $zdjety,
            'created_at' => $komentarz->created_at?->toIso8601String(),
            'author' => new AutorResource($komentarz->author),
            'parent_id' => $komentarz->parent_id,
            'replies' => $komentarz->parent_id === null && $komentarz->relationLoaded('replies')
                ? self::collection($komentarz->replies)->resolve($request)
                : [],
        ];
    }
}
