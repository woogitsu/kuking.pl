<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Http\Resources\Api\V1\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Wpis i jego komentarze w API (D-272). Wejście przez `PostPolicy::view` —
 * tę samą, która pilnuje `/wpisy/{post}` na WWW. UUID w adresie nie jest
 * autoryzacją (AGENTS.md §7).
 */
class PostController extends Controller
{
    public function show(Request $request, Post $post): PostResource
    {
        $this->authorize('view', $post);

        $post->load(['author.profile.avatar', 'media', 'tags:id,slug,name,status', 'recipe.heroMedia', 'recipe.author'])
            ->loadCount(['comments' => fn ($q) => $q->widoczneDla($request->user())]);

        return new PostResource($post);
    }

    public function comments(Request $request, Post $post): AnonymousResourceCollection
    {
        $this->authorize('view', $post);

        return CommentResource::collection(
            $post->comments()
                ->widoczneDla($request->user())
                ->with([
                    'author.profile.avatar',
                    'replies' => fn ($q) => $q->widoczneDla($request->user()),
                    'replies.author.profile.avatar',
                ])
                ->paginate((int) config('kuking.comments.page_size')),
        );
    }
}
