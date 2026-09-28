<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\WatkiKomentarzy;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dalsze odpowiedzi jednego wątku w API (issue #1970, D-272).
 *
 * Lista komentarzy wpisu i przepisu niesie przy każdym wątku najwyżej
 * `kuking.api.odpowiedzi_w_watku` odpowiedzi i adres `more_replies_url`
 * tutaj. Wejście przez `CommentPolicy::view` — tę samą, która sprawdza
 * blokady, moderację, korzeń i widoczność rodzica (wpis, przepis,
 * „Ugotowałem"). UUID w adresie nie jest autoryzacją (AGENTS.md §7).
 */
class CommentController extends Controller
{
    public function replies(Request $request, Comment $comment): AnonymousResourceCollection
    {
        $this->authorize('view', $comment);

        // Odpowiedzi mają tylko komentarze główne — rozmowa ma jeden poziom.
        abort_if($comment->parent_id !== null, 404);

        return CommentResource::collection(WatkiKomentarzy::odpowiedzi($comment, $request->user()));
    }
}
