<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\WatkiKomentarzy;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Http\Resources\Api\V1\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Przepis i jego komentarze w API (D-272), po UUID — nie po slugu jak WWW:
 * slug zmienia się razem z tytułem, a aplikacja trzyma identyfikator
 * w pamięci. Wejście przez `RecipePolicy::view`.
 */
class RecipeController extends Controller
{
    public function show(Request $request, string $przepis): RecipeResource
    {
        $model = $this->znajdz($przepis);

        $model->load([
            'author.profile.avatar',
            'heroMedia',
            'ingredients.unit',
            'steps.media',
        ]);

        return new RecipeResource($model);
    }

    public function comments(Request $request, string $przepis): AnonymousResourceCollection
    {
        $model = $this->znajdz($przepis);

        return CommentResource::collection(
            WatkiKomentarzy::strona($model->comments(), $request->user()),
        );
    }

    private function znajdz(string $id): Recipe
    {
        $model = Recipe::query()->findOrFail($id);

        $this->authorize('view', $model);

        return $model;
    }
}
