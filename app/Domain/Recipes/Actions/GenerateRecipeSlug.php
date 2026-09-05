<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\Recipe;
use Illuminate\Support\Str;

/**
 * Slug przepisu.
 *
 * Polskie znaki są transliterowane ("żurek" → "zurek"), bo URL z procentami
 * jest nieczytelny, gdy ktoś chce go przeczytać przez telefon albo wpisać
 * z kartki. Jednocześnie stary slug NIGDY nie umiera — przy zmianie tytułu
 * dopisujemy przekierowanie (patrz UpdateRecipeSlug), bo link wysłany córce
 * SMS-em musi działać po roku.
 */
final class GenerateRecipeSlug
{
    public function handle(string $title, ?string $ignoreRecipeId = null): string
    {
        $base = Str::slug(Str::ascii($title));

        if ($base === '') {
            $base = 'przepis';
        }

        $base = Str::limit($base, 200, '');
        $slug = $base;
        $suffix = 2;

        while ($this->taken($slug, $ignoreRecipeId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function taken(string $slug, ?string $ignoreRecipeId): bool
    {
        $query = Recipe::withTrashed()->where('slug', $slug);

        if ($ignoreRecipeId !== null) {
            $query->whereKeyNot($ignoreRecipeId);
        }

        return $query->exists();
    }
}
