<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Support\Collection;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent. Bez Typesense,
 * bez Meilisearch, bez osobnego indeksu (docs/ARCHITECTURE.md).
 *
 * Dlaczego trigramy, a nie pełnotekstowe FTS jako główna ścieżka: nasi
 * użytkownicy wpisują "zurek" szukając "żurku" i "pierogii" szukając
 * "pierogów". Podobieństwo trigramowe radzi sobie z literówkami i odmianą
 * lepiej niż stemming, którego dla polskiego w Postgresie po prostu nie ma.
 */
final class SearchQuery
{
    /** Poniżej tego progu podobieństwa wyniki są już przypadkowe. */
    private const SIMILARITY_THRESHOLD = 0.12;

    /** @return Collection<int, Recipe> */
    public function recipes(string $phrase, int $limit = 20): Collection
    {
        $phrase = trim($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        return Recipe::query()
            ->publiclyVisible()
            ->with(['author.profile', 'heroMedia'])
            ->withCount('cookedEvents')
            ->where(function ($query) use ($needle): void {
                $query
                    ->whereRaw('unaccent(lower(title)) % ?', [$needle])
                    ->orWhereRaw('unaccent(lower(title)) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('unaccent(lower(coalesce(summary, \'\'))) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereExists(function ($sub) use ($needle): void {
                        $sub->selectRaw('1')
                            ->from('recipe_ingredients')
                            ->whereColumn('recipe_ingredients.recipe_id', 'recipes.id')
                            ->whereRaw('unaccent(lower(ingredient_text)) LIKE ?', ['%'.$needle.'%']);
                    });
            })
            ->orderByRaw('similarity(unaccent(lower(title)), ?) DESC', [$needle])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Profile> */
    public function people(string $phrase, int $limit = 20): Collection
    {
        $phrase = trim($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        return Profile::query()
            ->with(['user', 'avatar'])
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($needle): void {
                $query
                    ->whereRaw('unaccent(lower(display_name)) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('lower(username) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('unaccent(lower(coalesce(speciality, \'\'))) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderByRaw('similarity(unaccent(lower(display_name)), ?) DESC', [$needle])
            ->limit($limit)
            ->get();
    }

    private function normalize(string $phrase): string
    {
        // Diakrytyki zdejmuje unaccent po stronie bazy; tu tylko małe litery
        // i sensowna długość, żeby nie wysyłać do bazy całych akapitów.
        return mb_strtolower(mb_substr($phrase, 0, 120));
    }
}
