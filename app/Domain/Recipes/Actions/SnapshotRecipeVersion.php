<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;

/**
 * Zapisanie migawki przepisu.
 *
 * Wołane przy publikacji i przy każdej istotnej zmianie. Snapshot jest
 * samowystarczalny — da się z niego odtworzyć przepis nawet gdyby
 * powiązane wiersze zniknęły.
 */
final class SnapshotRecipeVersion
{
    public function handle(Recipe $recipe, User $editor, ?string $changeNote = null): RecipeVersion
    {
        $recipe->loadMissing(['ingredients.unit', 'steps']);

        $next = (int) $recipe->versions()->max('version_number') + 1;

        return RecipeVersion::create([
            'recipe_id' => $recipe->getKey(),
            'editor_id' => $editor->getKey(),
            'version_number' => $next,
            'change_note' => $changeNote,
            'snapshot' => [
                'title' => $recipe->title,
                'summary' => $recipe->summary,
                'servings' => $recipe->servings,
                'prep_minutes' => $recipe->prep_minutes,
                'cook_minutes' => $recipe->cook_minutes,
                'difficulty' => $recipe->difficulty,
                'source_type' => $recipe->source_type,
                'source_person' => $recipe->source_person,
                'source_note' => $recipe->source_note,
                'family_since_year' => $recipe->family_since_year,
                'ingredients' => $recipe->ingredients->map(fn ($i) => [
                    'group_name' => $i->group_name,
                    'text' => $i->ingredient_text,
                    'quantity' => $i->quantity,
                    'unit' => $i->unit?->code,
                    'note' => $i->note,
                    'position' => $i->position,
                ])->all(),
                'steps' => $recipe->steps->map(fn ($s) => [
                    'position' => $s->position,
                    'instruction' => $s->instruction,
                    'timer_seconds' => $s->timer_seconds,
                ])->all(),
            ],
        ]);
    }
}
