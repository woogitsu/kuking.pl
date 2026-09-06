<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @param  User|null  $widz  kto szuka — potrzebny WYŁĄCZNIE do blokad
     * @return Collection<int, Recipe>
     */
    public function recipes(string $phrase, ?User $widz = null, int $limit = 20): Collection
    {
        $phrase = trim($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        return Recipe::query()
            ->publiclyVisible()
            // Konto autora aktywne (audyt A5) — bez tego wyszukiwarka
            // wypychała przepisy osoby zawieszonej albo zbanowanej na widok
            // każdego, kto akurat wpisał trafną frazę, mimo że jej profil
            // (link pod wynikiem) dawał 403.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->tap(fn ($query) => $this->pomijajZablokowanych($query, $widz, 'recipes.author_id'))
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

    /**
     * @param  User|null  $widz  kto szuka — potrzebny WYŁĄCZNIE do blokad
     * @return Collection<int, Profile>
     */
    public function people(string $phrase, ?User $widz = null, int $limit = 20): Collection
    {
        $phrase = trim($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        return Profile::query()
            ->with(['user', 'avatar'])
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->tap(fn ($query) => $this->pomijajZablokowanych($query, $widz, 'profiles.user_id'))
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
     * Wycięcie z wyników wszystkiego, co należy do osoby w relacji blokady
     * z szukającym (issue #41).
     *
     * DLACZEGO TO MUSI BYĆ TUTAJ, A NIE W POLICY
     * Wyszukiwarka to osobne zapytanie. Policy pilnuje wejścia na adres treści,
     * filtry profilu pilnują listy profilu — żadne z nich nie dotyczy tego
     * zapytania. Bez tego filtra blokada znaczyła tylko „nie zobaczę tej osoby,
     * dopóki nie użyję wyszukiwarki", co jest obietnicą bez pokrycia.
     *
     * Blokada działa w OBIE strony (`AGENTS.md` §4): nieważne, kto kogo
     * zablokował — stąd dwa warunki w `orWhere`.
     *
     * Dla gościa (`$widz === null`) nie ma czego filtrować: blokada jest relacją
     * między dwoma kontami.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $kolumnaAutora  w pełni kwalifikowana kolumna z id właściciela treści
     */
    private function pomijajZablokowanych($query, ?User $widz, string $kolumnaAutora): void
    {
        if ($widz === null) {
            return;
        }

        $query->whereNotExists(function ($sub) use ($widz, $kolumnaAutora): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widz, $kolumnaAutora): void {
                    $w->where('blocks.blocker_id', $widz->getKey())
                        ->whereColumn('blocks.blocked_id', $kolumnaAutora);
                })
                ->orWhere(function ($w) use ($widz, $kolumnaAutora): void {
                    $w->whereColumn('blocks.blocker_id', $kolumnaAutora)
                        ->where('blocks.blocked_id', $widz->getKey());
                });
        });
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
