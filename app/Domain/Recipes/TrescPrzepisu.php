<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use Illuminate\Support\Facades\DB;

/**
 * Odcisk TREŚCI przepisu — tego, co czytelnik widzi na stronie przepisu (#2014).
 *
 * Służy jednej rzeczy: `PublishRecipe` porównuje odcisk sprzed zapisu
 * i po nim, i tylko przy różnicy przestawia `recipes.tresc_zmieniona_at`
 * (źródło `dateModified` w JSON-LD). Dlatego odcisk NIE obejmuje stanu
 * przepisu ani jego obiegu: `status`, `visibility`, `published_at`,
 * `content_revision`, `slug` i znaczników czasu. Zmiana widoczności,
 * moderacja i zapis bez zmian nie są zmianą treści. Poza odciskiem jest też
 * `pokazuj_wartosci_odzywcze` — to ustawienie wyświetlania, nie treść przepisu.
 *
 * Obejmuje natomiast zdjęcia (główne, skan źródła, zdjęcia kroków) — czego
 * migawka wersji (`SnapshotRecipeVersion`) nie robi, więc to nie jest ta
 * sama lista i nie wolno jej z tamtą „ujednolicić".
 *
 * Czyta prosto z bazy, nie z relacji modelu: relacja załadowana przed
 * `syncIngredients()` pokazywałaby stan sprzed zapisu także „po".
 */
final class TrescPrzepisu
{
    /** Kolumny `recipes`, które są treścią przepisu. */
    private const KOLUMNY_PRZEPISU = [
        'title', 'summary', 'servings', 'prep_minutes', 'cook_minutes', 'difficulty',
        'estimated_cost_pln', 'hero_media_id', 'source_type', 'source_url',
        'source_person', 'source_note', 'family_since_year', 'source_scan_media_id',
    ];

    private const KOLUMNY_SKLADNIKA = [
        'position', 'group_name', 'ingredient_text', 'quantity', 'unit_id',
        'note', 'substitutes', 'no_amount',
    ];

    private const KOLUMNY_KROKU = ['position', 'instruction', 'timer_seconds', 'media_id'];

    /** @return array<string, mixed> */
    public static function odcisk(string $recipeId): array
    {
        return [
            'przepis' => (array) DB::table('recipes')->where('id', $recipeId)->first(self::KOLUMNY_PRZEPISU),
            'skladniki' => DB::table('recipe_ingredients')->where('recipe_id', $recipeId)
                ->orderBy('position')->orderBy('id')->get(self::KOLUMNY_SKLADNIKA)
                ->map(static fn (object $wiersz): array => (array) $wiersz)->all(),
            'kroki' => DB::table('recipe_steps')->where('recipe_id', $recipeId)
                ->orderBy('position')->orderBy('id')->get(self::KOLUMNY_KROKU)
                ->map(static fn (object $wiersz): array => (array) $wiersz)->all(),
        ];
    }
}
