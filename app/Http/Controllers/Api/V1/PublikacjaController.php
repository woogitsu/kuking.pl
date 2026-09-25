<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\PublishPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Api\V1\Concerns\PrzyjmujeZdjecia;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PostResource;
use App\Models\Post;
use App\Support\LimityTagow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/wpisy` — główna akcja produktu: „Co dziś ugotowałeś?" →
 * zdjęcie + kilka słów → Opublikuj (D-273).
 *
 * ADAPTER (D-014): zdjęcia przez `StoreUploadedImage`, wpis przez
 * `PublishPost` — te same akcje co `PostController::store()` na WWW,
 * z tymi samymi limitami i komunikatami. `multipart/form-data`:
 * `photos[]`, `body`, `visibility`, opcjonalnie `tags[]`.
 */
class PublikacjaController extends Controller
{
    use PrzyjmujeZdjecia;

    public function store(Request $request, StoreUploadedImage $zapisZdjecia, PublishPost $publikuj): JsonResponse
    {
        $dane = $request->validate([
            ...$this->regulyZdjec(),
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => ['required', 'in:public,followers,private'],
            'tags' => ['nullable', 'array', 'max:'.LimityTagow::maksTagowNaWpis()],
            'tags.*' => ['string', 'max:'.LimityTagow::maksZnakow()],
        ], [
            ...$this->komunikatyZdjec(),
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
            'tags.max' => LimityTagow::komunikatZaDuzoTagow(),
        ]);

        $autor = $request->user();
        $zdjecia = $this->zapiszZdjecia($request, $autor, $zapisZdjecia);

        try {
            $wpis = $publikuj->handle(
                author: $autor,
                body: $dane['body'] ?? null,
                mediaIds: $zdjecia,
                visibility: $dane['visibility'],
                tagNames: array_values($dane['tags'] ?? []),
                ip: $request->ip(),
                displayMode: Post::DISPLAY_NORMAL,
                kluczWyslania: $this->kluczWyslania($request),
            );
        } catch (BladDlaCzlowieka $e) {
            $pole = $e->getMessage() === LimityTagow::komunikatZaDuzoTagow() ? 'tags' : 'photos';

            throw ValidationException::withMessages([$pole => $e->getMessage()]);
        }

        $wpis->load(['author.profile.avatar', 'media', 'tags:id,slug,name,status', 'recipe.heroMedia']);

        return (new PostResource($wpis))
            ->response()
            ->setStatusCode($wpis->wasRecentlyCreated ? 201 : 200);
    }
}
