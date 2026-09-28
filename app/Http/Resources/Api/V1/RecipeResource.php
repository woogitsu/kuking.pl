<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Przepis w API (D-272). O tym, czy wolno go pokazać, decyduje
 * `RecipePolicy::view` w kontrolerze. Zasób nie wypuszcza `klucz_wyslania`,
 * statusu moderacji, skanu źródła (`source_scan_media_id` — skan kartki
 * z zeszytu bywa prywatny i ma własną Policy na WWW) ani adresu źródła
 * poza `http(s)`.
 *
 * @mixin Recipe
 */
class RecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recipe $przepis */
        $przepis = $this->resource;

        return [
            'id' => (string) $przepis->getKey(),
            'slug' => $przepis->slug,
            'title' => $przepis->title,
            'summary' => $przepis->summary,
            'servings' => $przepis->servings,
            'prep_minutes' => $przepis->prep_minutes,
            'cook_minutes' => $przepis->cook_minutes,
            'difficulty' => $przepis->difficulty,
            'visibility' => $przepis->visibility,
            'published_at' => $przepis->published_at?->toIso8601String(),
            'author' => new AutorResource($przepis->author),
            'hero_photo' => Zdjecie::z($przepis->heroMedia),
            'attribution' => $przepis->attributionLine(),
            'ingredients' => $przepis->ingredients->map(fn (RecipeIngredient $s): array => [
                'group' => $s->group_name,
                'text' => $s->ingredient_text,
                'quantity' => $s->no_amount ? null : $s->quantity,
                'unit' => $s->unit?->name,
                'note' => $s->note,
            ])->values()->all(),
            'steps' => $przepis->steps->map(fn (RecipeStep $k): array => [
                'position' => $k->position,
                'instruction' => $k->instruction,
                'timer_seconds' => $k->timer_seconds,
                'photo' => Zdjecie::z($k->media),
            ])->values()->all(),
            'url' => $przepis->url(),
        ];
    }
}
