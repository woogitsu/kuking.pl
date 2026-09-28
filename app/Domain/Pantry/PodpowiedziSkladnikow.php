<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;

/**
 * Podpowiedzi pod polem „Co masz w domu?” — ze słownika składników,
 * które występują w przepisach (D-285).
 *
 * TYLKO SKŁADNIKI Z PRZEPISÓW PUBLICZNYCH I OPUBLIKOWANYCH.
 * Słownik `ingredients` rośnie z każdego zapisanego przepisu — także ze
 * szkicu i z przepisu „tylko dla mnie”. Podpowiedź z takiego przepisu
 * zdradzałaby cudzą linijkę („sos babci Haliny wg zeszytu z 1978”), której
 * autor nikomu nie pokazał. Stąd warunek: hasło musi stać w co najmniej
 * jednym przepisie publicznym, opublikowanym, od aktywnego konta.
 *
 * KOLEJNOŚĆ: NAJKRÓTSZE NAJPIERW, POTEM ALFABET.
 * Nie „najczęściej używane” — to byłaby miara cudzej aktywności (AGENTS.md §8
 * i §12). Krótsza nazwa jest bliżej tego, co ktoś wpisał: po „mak” lepiej
 * zobaczyć „mąka” niż „mąka pszenna typ 650 do chleba”.
 */
final class PodpowiedziSkladnikow
{
    public const MAKS_PODPOWIEDZI = 8;

    public const MIN_ZNAKOW = 2;

    public const MAKS_ZNAKOW = 60;

    /** @return list<string> */
    public function dla(string $fraza, User $widz): array
    {
        $fraza = trim($fraza);

        if (mb_strlen($fraza) < self::MIN_ZNAKOW) {
            return [];
        }

        $wzorzec = '%'.$this->uciecznijLike(Ingredient::normalize($fraza)).'%';

        $juzNaLiscie = $widz->pantryItems()->pluck('klucz')->all();

        return Ingredient::query()
            // Indeks `ingredients_name_trgm_idx` stoi na tym wyrażeniu.
            ->whereRaw('public.kuking_normalize(normalized_name) LIKE ?', [$wzorzec])
            ->whereIn('ingredients.id', RecipeIngredient::query()
                ->select('ingredient_id')
                ->whereNotNull('ingredient_id')
                ->whereIn('recipe_id', Recipe::query()
                    ->select('recipes.id')
                    ->publiclyVisible()
                    ->whereHas('author', fn ($autor) => $autor->where('status', User::STATUS_ACTIVE))))
            ->when($juzNaLiscie !== [], fn ($q) => $q->whereRaw(
                'public.kuking_klucz_skladnika(canonical_name) <> ALL (?::text[])',
                ['{'.implode(',', array_map(fn (string $k): string => '"'.$k.'"', $juzNaLiscie)).'}'],
            ))
            ->orderByRaw('char_length(canonical_name), canonical_name')
            ->limit(self::MAKS_PODPOWIEDZI)
            ->pluck('canonical_name')
            ->map(fn ($nazwa): string => (string) $nazwa)
            ->values()
            ->all();
    }

    private function uciecznijLike(string $tekst): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tekst);
    }
}
