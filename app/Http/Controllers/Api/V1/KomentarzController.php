<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Comments\Actions\PublishComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Komentarz pod wpisem i pod przepisem (D-273) — przez `PublishComment`,
 * tę samą akcję co WWW, z tą samą bramką: `PostPolicy::comment` dla wpisu,
 * `RecipePolicy::view` dla przepisu (jak `RecipeController::comment`).
 * Odpowiedź na komentarz (`parent_id`) szuka rodzica WYŁĄCZNIE wśród
 * komentarzy widocznych dla piszącego — cudzy albo zablokowany rodzic nie
 * da się wskazać samym identyfikatorem.
 */
class KomentarzController extends Controller
{
    public function __construct(private readonly PublishComment $publikuj) {}

    public function storePost(Request $request, Post $post): JsonResponse
    {
        $this->authorize('comment', $post);

        $dane = $this->dane($request);

        return $this->zapisz($request, $post, $dane, fn (string $id) => $post->allComments()
            ->widoczneDla($request->user())
            ->whereKey($id)
            ->first());
    }

    public function storeRecipe(Request $request, string $przepis): JsonResponse
    {
        $model = Recipe::query()->findOrFail($przepis);
        $this->authorize('view', $model);

        $dane = $this->dane($request);

        return $this->zapisz($request, $model, $dane, fn (string $id) => $model->comments()
            ->widoczneDla($request->user())
            ->whereKey($id)
            ->first());
    }

    /**
     * @return array{body: string, parent_id?: string|null}
     */
    private function dane(Request $request): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);
    }

    /**
     * @param  array{body: string, parent_id?: string|null}  $dane
     * @param  callable(string): mixed  $rodzic
     */
    private function zapisz(Request $request, Post|Recipe $przedmiot, array $dane, callable $rodzic): JsonResponse
    {
        $parentId = $dane['parent_id'] ?? null;

        try {
            $komentarz = $this->publikuj->handle(
                author: $request->user(),
                subject: $przedmiot,
                body: $dane['body'],
                parent: $parentId === null ? null : $rodzic($parentId),
                parentRequested: $parentId !== null,
            );
        } catch (BladDlaCzlowieka $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        $komentarz->load('author.profile.avatar');

        return (new CommentResource($komentarz))->response()->setStatusCode(201);
    }
}
