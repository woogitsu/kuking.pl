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
 * `Comment::scopeWidoczneDla()` (blokady w obie strony) i jest ich najwyżej
 * kilka na wątek — reszta pod `more_replies_url` (#1970, `WatkiKomentarzy`).
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
        $korzenZOdpowiedziami = $komentarz->parent_id === null && $komentarz->relationLoaded('replies');
        $liczbaOdpowiedzi = $komentarz->parent_id === null && $komentarz->replies_count !== null
            ? (int) $komentarz->replies_count
            : null;

        return [
            'id' => (string) $komentarz->getKey(),
            'body' => $zdjety ? null : $komentarz->body,
            'removed' => $zdjety,
            'created_at' => $komentarz->created_at?->toIso8601String(),
            'author' => new AutorResource($komentarz->author),
            'parent_id' => $komentarz->parent_id,
            'replies' => $korzenZOdpowiedziami
                ? self::collection($komentarz->replies)->resolve($request)
                : [],
            // #1970: wątek niesie najwyżej `kuking.api.odpowiedzi_w_watku`
            // najstarszych odpowiedzi. `replies_count` to wszystkie widoczne
            // dla widza; resztę, od pierwszej, oddaje `more_replies_url`.
            'replies_count' => $liczbaOdpowiedzi,
            'more_replies_url' => $korzenZOdpowiedziami && $liczbaOdpowiedzi !== null && $liczbaOdpowiedzi > $komentarz->replies->count()
                ? route('api.komentarze.odpowiedzi', ['comment' => $komentarz->getKey()])
                : null,
        ];
    }
}
