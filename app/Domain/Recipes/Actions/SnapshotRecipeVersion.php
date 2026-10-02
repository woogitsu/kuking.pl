<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zapisanie migawki przepisu.
 *
 * Wołane przy publikacji i przy każdej istotnej zmianie. Snapshot jest
 * samowystarczalny — da się z niego odtworzyć przepis nawet gdyby
 * powiązane wiersze zniknęły.
 *
 * `source_url` i `ingredients[].no_amount` są w migawce od issue #896.
 * Starsze migawki ich NIE MAJĄ i brak klucza znaczy „nieznane" — nie wolno
 * go uzupełniać dzisiejszą wartością z przepisu, bo to byłby zmyślony stan
 * historyczny. `no_amount` nie da się też wywnioskować z `quantity = null`:
 * „ilości nie podano" i „bez ilości" to w bazie dwa różne stany.
 *
 * `allergen_status` i `allergens` (#1902) działają tak samo: migawki sprzed
 * tej zmiany ich nie mają i brak klucza znaczy „nieznane”, NIE „nie sprawdzono”
 * z dzisiaj. Żeby pierwsze „Zapisz zmiany” po wdrożeniu nie zakładało wersji
 * tylko dlatego, że w nowej migawce doszły dwa klucze, `poprawka()` porównuje
 * z ostatnią wersją BEZ nich, gdy ta ich nie miała, a przepis jest niesprawdzony.
 */
final class SnapshotRecipeVersion
{
    /*
     * Opis wersji ze świadomego zapisu bez publikacji na już opublikowanym
     * przepisie (issue #1316): „Zapisz zmiany" albo wyjście z kreatora.
     */
    public const OPIS_POPRAWKI = 'Poprawka opublikowanego przepisu';

    public function handle(Recipe $recipe, User $editor, ?string $changeNote = null): RecipeVersion
    {
        return DB::transaction(function () use ($recipe, $editor, $changeNote): RecipeVersion {
            /*
             * NUMER POD BLOKADĄ PRZEPISU (issue #895). `max() + 1` bez
             * serializacji per przepis daje dwóm równoległym zapisom ten sam
             * numer. `PublishRecipe` trzyma już tę blokadę (jego `UPDATE`
             * i `lockForUpdate()` — `tests/Dwa/NumerWersjiPrzepisuNieKolidujeTest.php`),
             * więc tam to nie jest nowa blokada ani nowa kolejność: ten sam
             * wiersz, ta sama transakcja. Stoi tu, żeby klasa była poprawna
             * sama z siebie, a nie tylko dzięki temu, kto ją woła.
             */
            Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first([$recipe->getKeyName()]);

            $recipe->loadMissing(['ingredients.unit', 'steps']);

            $next = (int) $recipe->versions()->max('version_number') + 1;

            return RecipeVersion::create([
                'recipe_id' => $recipe->getKey(),
                'editor_id' => $editor->getKey(),
                'version_number' => $next,
                'change_note' => $changeNote,
                'snapshot' => $this->migawka($recipe),
            ]);
        });
    }

    /**
     * Historia dla ŚWIADOMEGO zapisu BEZ publikacji na przepisie, który JEST
     * publiczny (issue #1316): „Zapisz zmiany" i wyjście z kreatora,
     * `action=draft` w formularzu bez JavaScriptu. Autozapis tu nie trafia.
     *
     * - treść równa ostatniej wersji → nic (drugie „Zapisz zmiany" bez zmian
     *   nie mnoży wersji);
     * - inaczej → NOWA wersja z opisem `$opis` (domyślnie `OPIS_POPRAWKI`;
     *   ponowną publikację istniejącego przepisu woła `PublishRecipe` z opisem
     *   „Aktualizacja przepisu" — wersja identyczna z poprzednią nie powstaje, #2024).
     *
     * ISTNIEJĄCEJ WERSJI NIGDY NIE ZMIENIAMY (decyzja właściciela z 24.09.2026).
     * Wersja jest zamrożonym obrazem tego, co ktoś kiedyś widział i z czego
     * gotował — także wersja-poprawka tej samej osoby sprzed minuty.
     */
    public function poprawka(Recipe $recipe, User $editor, string $opis = self::OPIS_POPRAWKI): ?RecipeVersion
    {
        return DB::transaction(function () use ($recipe, $editor, $opis): ?RecipeVersion {
            // Ta sama blokada co w `handle()`: ostatnia wersja przeczytana
            // pod nią nie zmieni się, zanim ją porównamy.
            Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first([$recipe->getKeyName()]);

            $recipe->loadMissing(['ingredients.unit', 'steps']);

            $migawka = $this->migawka($recipe);
            $ostatnia = $recipe->versions()->first();

            $doPorownania = $migawka;
            if ($ostatnia !== null
                && ! array_key_exists('allergen_status', $ostatnia->snapshot)
                && $migawka['allergen_status'] === Recipe::ALERGENY_NIESPRAWDZONE) {
                unset($doPorownania['allergen_status'], $doPorownania['allergens']);
            }

            // `==`, nie `===`: jsonb nie zachowuje kolejności kluczy obiektu.
            if ($ostatnia !== null && $ostatnia->snapshot == $doPorownania) {
                return null;
            }

            return RecipeVersion::create([
                'recipe_id' => $recipe->getKey(),
                'editor_id' => $editor->getKey(),
                'version_number' => (int) $recipe->versions()->max('version_number') + 1,
                'change_note' => $opis,
                'snapshot' => $migawka,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function migawka(Recipe $recipe): array
    {
        return [
            'title' => $recipe->title,
            'summary' => $recipe->summary,
            'servings' => $recipe->servings,
            // Gotowe sztuki (#2645) — osobno od porcji; oba klucze zawsze razem.
            'yield_count' => $recipe->yield_count,
            'yield_unit' => $recipe->yield_unit,
            'estimated_cost_pln' => $recipe->estimated_cost_pln,
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'difficulty' => $recipe->difficulty,
            'source_type' => $recipe->source_type,
            'source_url' => $recipe->source_url,
            'source_person' => $recipe->source_person,
            'source_note' => $recipe->source_note,
            'family_since_year' => $recipe->family_since_year,
            // Oznaczenie alergenów według autora (#1902) — zawsze razem:
            // sama lista bez stanu mogłaby zostać odczytana jako „brak alergenów”.
            'allergen_status' => $recipe->allergen_status ?? Recipe::ALERGENY_NIESPRAWDZONE,
            'allergens' => $recipe->allergens,
            'ingredients' => $recipe->ingredients->map(fn ($i) => [
                'group_name' => $i->group_name,
                'text' => $i->ingredient_text,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->code,
                'note' => $i->note,
                'substitutes' => $i->substitutes,
                'no_amount' => (bool) $i->no_amount,
                'position' => $i->position,
            ])->all(),
            'steps' => $recipe->steps->map(fn ($s) => [
                'position' => $s->position,
                'instruction' => $s->instruction,
                'timer_seconds' => $s->timer_seconds,
                // Nazwa etapu (#2652); starsze migawki jej nie mają = brak nagłówka.
                'section_name' => $s->section_name,
            ])->all(),
        ];
    }
}
