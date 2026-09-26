<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * „Co ugotuję z tego, co mam” (V2, D-285).
 *
 * REGUŁA DOBORU, JEDNYM ZDANIEM (pokazywana też na ekranie):
 *
 *     Najpierw przepisy, do których masz najwięcej — czyli brakuje w nich
 *     najmniej składników z Twojej listy; przy tej samej liczbie brakujących
 *     najpierw te, które zajmują najmniej czasu.
 *
 * Nic poza tym. Kolejność NIE zależy od reakcji innych ludzi — ani od
 * „Ugotowałem”, ani od zapisów w zeszytach, ani od obserwujących
 * (AGENTS.md §8 i §12). Pilnuje tego `FeedNieSortujePoMierzeReakcjiTest`,
 * który skanuje cały katalog `app/Domain/Pantry`.
 *
 * DOPASOWANIE SKŁADNIKA
 * Linijka składnika w przepisie „jest w domu”, gdy któryś produkt z listy
 * ma wszystkie swoje rdzenie w rdzeniach tej linijki:
 * `pantry_items.rdzenie <@ public.kuking_rdzenie_skladnika(ingredient_text)`.
 * Reguła rdzeni (małe litery, bez polskich znaków, prosta liczba mnoga)
 * jest opisana w migracji `2026_09_26_120000_create_pantry_items_table`
 * i mieszka wyłącznie w bazie. Bez AI.
 *
 * KTÓRE PRZEPISY W OGÓLE WCHODZĄ
 * Te same, które ta osoba może otworzyć (`widoczneDla`), wyłącznie
 * opublikowane (`published()` PRZED `widoczneDla()` — bez tego weszłyby
 * własne szkice, tak jak w `SearchQuery`), od aktywnych kont, i tylko takie,
 * w których pasuje co najmniej jeden składnik. Przepis, do którego nie masz
 * nic, nie jest odpowiedzią na pytanie „co ugotuję z tego, co mam”.
 */
final class CoUgotuje
{
    public const NA_STRONE = 20;

    /**
     * Zdanie z regułą — jedno źródło dla widoku i dokumentacji.
     */
    public const REGULA = 'Najpierw przepisy, do których masz najwięcej — czyli brakuje w nich najmniej składników z Twojej listy. '
        .'Przy tej samej liczbie brakujących najpierw te, które zajmują najmniej czasu.';

    /**
     * Warunek „ta linijka składnika jest na liście tej osoby”. `ri` to alias
     * `recipe_ingredients` w zapytaniu, które go używa.
     */
    private const MAM_SQL = 'EXISTS (SELECT 1 FROM pantry_items p WHERE p.user_id = ? '
        .'AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text))';

    /**
     * @return array{
     *     przepisy: Collection<int, Recipe>,
     *     brakujace: array<string, list<string>>,
     *     jest_wiecej: bool,
     *     produktow: int
     * }
     */
    public function dla(User $widz, int $offset = 0, int $limit = self::NA_STRONE): array
    {
        $uid = (string) $widz->getKey();
        $offset = max(0, $offset);

        // Po jednym, najdłuższym rdzeniu z każdego produktu: wstępny filtr
        // `LIKE` na `ingredient_text_search`, który może pójść po indeksie
        // trigramowym `recipe_ingredients_text_trgm_idx`. Rdzeń jest zawsze
        // fragmentem znormalizowanej linijki, więc ten filtr niczego
        // prawdziwego nie gubi — tylko zawęża kandydatów przed dokładnym
        // porównaniem tablic.
        $rdzenie = collect(DB::select(
            'SELECT DISTINCT ON (p.id) t AS rdzen FROM pantry_items p, unnest(p.rdzenie) AS t '
            .'WHERE p.user_id = ? ORDER BY p.id, length(t) DESC, t',
            [$uid],
        ))->pluck('rdzen')->unique()->values();

        if ($rdzenie->isEmpty()) {
            return ['przepisy' => new Collection, 'brakujace' => [], 'jest_wiecej' => false, 'produktow' => 0];
        }

        $wstepnie = implode(' OR ', array_fill(0, $rdzenie->count(), 'ri.ingredient_text_search LIKE ?'));
        $wzorce = $rdzenie->map(fn (string $r): string => '%'.$r.'%')->all();

        $wiersze = Recipe::query()
            ->published()
            ->widoczneDla($widz)
            ->whereHas('author', fn ($autor) => $autor->where('status', User::STATUS_ACTIVE))
            ->whereRaw(
                'recipes.id IN (SELECT ri.recipe_id FROM recipe_ingredients ri WHERE ('.$wstepnie.') AND '.self::MAM_SQL.')',
                [...$wzorce, $uid],
            )
            ->select('recipes.*')
            ->selectRaw('(SELECT count(*) FROM recipe_ingredients ri WHERE ri.recipe_id = recipes.id) AS skladnikow_razem')
            ->selectRaw(
                '(SELECT count(*) FROM recipe_ingredients ri WHERE ri.recipe_id = recipes.id AND NOT '.self::MAM_SQL.') AS skladnikow_brakuje',
                [$uid],
            )
            ->with(Recipe::RELACJE_KARTY)
            // REGUŁA — patrz nagłówek klasy. Najpierw liczba brakujących,
            // potem łączny czas (przepis bez podanego czasu na końcu remisu:
            // brak danych nie znaczy „szybki”, ta sama zasada co filtr
            // „Do 30 minut” w wyszukiwarce), potem czas publikacji i `id`
            // wyłącznie po to, żeby kolejność była stała między stronami.
            ->orderBy('skladnikow_brakuje')
            ->orderByRaw('(recipes.prep_minutes + recipes.cook_minutes) ASC NULLS LAST')
            ->orderByDesc('recipes.published_at')
            ->orderBy('recipes.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $jestWiecej = $wiersze->count() > $limit;
        $przepisy = $wiersze->take($limit)->values();

        return [
            'przepisy' => $przepisy,
            'brakujace' => $this->brakujace($przepisy, $uid),
            'jest_wiecej' => $jestWiecej,
            'produktow' => $widz->pantryItems()->count(),
        ];
    }

    /**
     * Linijki składników, których brakuje — dosłownie tak, jak napisał je
     * autor przepisu, w kolejności z przepisu.
     *
     * @param  Collection<int, Recipe>  $przepisy
     * @return array<string, list<string>> id przepisu => brakujące linijki
     */
    private function brakujace(Collection $przepisy, string $uid): array
    {
        if ($przepisy->isEmpty()) {
            return [];
        }

        $wynik = array_fill_keys($przepisy->modelKeys(), []);

        RecipeIngredient::query()
            ->from('recipe_ingredients as ri')
            ->whereIn('ri.recipe_id', $przepisy->modelKeys())
            ->whereRaw('NOT '.self::MAM_SQL, [$uid])
            ->orderBy('position')
            ->get(['ri.recipe_id', 'ri.ingredient_text'])
            ->each(function (RecipeIngredient $linijka) use (&$wynik): void {
                $wynik[(string) $linijka->recipe_id][] = (string) $linijka->ingredient_text;
            });

        return $wynik;
    }
}
