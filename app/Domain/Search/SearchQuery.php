<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent. Bez Typesense,
 * bez Meilisearch, bez osobnego indeksu (docs/ARCHITECTURE.md).
 *
 * WSZYSTKIE porównania idą przez `kuking_normalize()` — funkcję z migracji
 * 2026_09_05_001300, na której stoją indeksy GIN. Zapytanie MUSI używać
 * dokładnie tego samego wyrażenia co indeks, inaczej PostgreSQL go nie użyje
 * i każde wyszukiwanie skanuje całą tabelę. Tak było w pierwszej wersji:
 * indeksy stały na surowych kolumnach, a zapytania pytały o
 * `unaccent(lower(...))`. Jeśli zmieniasz tu wyrażenie — zmień też migrację.
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
                    ->whereRaw('kuking_normalize(title) % ?', [$needle])
                    ->orWhereRaw('kuking_normalize(title) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('kuking_normalize(coalesce(summary, \'\')) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereExists(function ($sub) use ($needle): void {
                        $sub->selectRaw('1')
                            ->from('recipe_ingredients')
                            ->whereColumn('recipe_ingredients.recipe_id', 'recipes.id')
                            ->whereRaw('kuking_normalize(ingredient_text) LIKE ?', ['%'.$needle.'%']);
                    });
            })
            ->orderByRaw('similarity(kuking_normalize(title), ?) DESC', [$needle])
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
                    ->whereRaw('kuking_normalize(display_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('kuking_normalize(username) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('kuking_normalize(coalesce(speciality, \'\')) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderByRaw('similarity(kuking_normalize(display_name), ?) DESC', [$needle])
            ->limit($limit)
            ->get();
    }

    /**
     * Fraza po stronie PHP musi być znormalizowana TAK SAMO jak kolumna
     * po stronie bazy — inaczej „Żurek" nie znajdzie „żurek".
     *
     * Str::ascii odpowiada temu, co robi `unaccent` z polskimi znakami
     * diakrytycznymi. Ograniczenie długości chroni przed wysyłaniem do bazy
     * całych akapitów i przed kosztownym `similarity()` na długim tekście.
     */
    private function normalize(string $phrase): string
    {
        return mb_strtolower(Str::ascii(mb_substr($phrase, 0, 120)));
    }
}
