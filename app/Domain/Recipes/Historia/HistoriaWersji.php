<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Kto i co może zobaczyć w historii wersji przepisu (issue #2024).
 *
 * JEDNA BRAMKA DLA WSZYSTKICH TRZECH EKRANÓW (lista, wersja, zmiany) i dla
 * linku „Historia zmian" na stronie przepisu — cztery miejsca nie mogą
 * odpowiadać na to samo pytanie czterema warunkami.
 *
 * REGUŁA: historia jest widoczna dokładnie wtedy, gdy widoczny jest przepis
 * (`RecipePolicy::view` — widoczność, blokady, konto autora), ORAZ przepis jest
 * opublikowany. Drugi warunek jest osobny, bo `RecipePolicy::view` wpuszcza
 * moderatora do przepisu ukrytego albo zdjętego, a historia takiej treści nie
 * ma prawa wyciec nikomu — nawet moderatorowi, który nie ma ku temu sprawy.
 * Wersje powstają wyłącznie przy publikacji i poprawce przepisu
 * opublikowanego (`SnapshotRecipeVersion`), więc szkicu tu nie ma z definicji;
 * bramka `isPublished()` pilnuje reszty.
 *
 * Wersje czytamy BEZ `editor_id` — ekran nie pokazuje edytora, więc zapytanie
 * nawet go nie wybiera.
 */
final class HistoriaWersji
{
    /** Ile wersji na stronę listy. */
    public const NA_STRONE = 20;

    /** Od ilu wersji historia w ogóle ma sens (jest co porównać). */
    public const MINIMUM_DO_POKAZANIA = 2;

    /**
     * Drugi warunek bramki: przepis musi być opublikowany. Woła go kontroler
     * PO `authorize('view')`, więc `RecipePolicy::view` nie jest liczone drugi
     * raz (zbędne zapytania) — kolejność to: view (403), potem opublikowanie (404).
     * Dla widza, który nie przeszedł `view`, użyj `wolnoOgladac()`.
     */
    public static function opublikowanyPoAutoryzacji(Recipe $recipe): bool
    {
        return $recipe->isPublished();
    }

    public static function wolnoOgladac(?User $widz, Recipe $recipe): bool
    {
        return $recipe->isPublished()
            && Gate::forUser($widz)->allows('view', $recipe);
    }

    /**
     * @return Builder<RecipeVersion>
     */
    public static function zapytanie(Recipe $recipe): Builder
    {
        return RecipeVersion::query()
            ->where('recipe_id', $recipe->getKey())
            ->select(['id', 'recipe_id', 'version_number', 'change_note', 'snapshot', 'created_at']);
    }

    /**
     * Numery wszystkich wersji, malejąco — jedno lekkie zapytanie (bez migawek)
     * na sąsiadów „starsza/nowsza".
     *
     * @return list<int>
     */
    public static function numery(Recipe $recipe): array
    {
        return RecipeVersion::query()
            ->where('recipe_id', $recipe->getKey())
            ->orderByDesc('version_number')
            ->pluck('version_number')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Czy pod przepisem pokazać link „Historia zmian" (≥ 2 wersje i wolno
     * oglądać). Woła `RecipeController::show()` PO `authorize('view')`, więc
     * `RecipePolicy::view` nie jest tu liczone drugi raz (zbędne zapytania) —
     * zostaje warunek „opublikowany", którego `view` nie stawia moderatorowi.
     * Dla widza, który nie przeszedł `view`, użyj `wolnoOgladac()`.
     */
    public static function pokazacLinkPoAutoryzacji(Recipe $recipe): bool
    {
        return $recipe->isPublished()
            && RecipeVersion::query()->where('recipe_id', $recipe->getKey())->count() >= self::MINIMUM_DO_POKAZANIA;
    }
}
