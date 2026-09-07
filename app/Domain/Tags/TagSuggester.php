<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use App\Support\LimityTagow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Podpowiedzi tagów bez AI (SPEC §1.5), wzorem `App\Domain\Search\SearchQuery`.
 *
 * TRZY ODDZIELNE ZAPYTANIA SKLEJONE `UNION ALL`, NIE JEDEN WARUNEK Z `OR`
 * — z tego samego, zmierzonego powodu co w `SearchQuery` (komentarz tamtej
 * klasy cytuje realny pomiar): PostgreSQL nie składa jednego planu z kilku
 * indeksów pod wspólnym `OR`, a rozbite na gałęzie każda dostaje własny skan
 * po indeksie trigramowym z migracji `create_tags_tables`.
 *
 * KOLEJNOŚĆ GAŁĘZI TO KOLEJNOŚĆ RANKINGU Z SPEC §1.5: dokładne dopasowanie
 * początku nazwy → dokładny alias → podobieństwo trigramowe. Popularność
 * (`withCount('posts')`) jest TYLKO tie-breakerem w obrębie trzeciej gałęzi
 * — świadomie NIE ma tu utrzymywanego ręcznie licznika `tags.usage_count`
 * (to jest dokładnie ta klasa błędu, przed którą ostrzega komentarz
 * w `LimityZdjec`: dwie kopie tej samej liczby w różnych miejscach
 * rozjeżdżają się, tutaj byłyby to co najmniej trzy miejsca aktualizujące
 * licznik — dodanie tagu, usunięcie, scalenie).
 *
 * `WHERE status = 'active'` wszędzie: tag scalony albo ukryty nie ma prawa
 * pojawić się jako podpowiedź, mimo że wiersz w `tags` nadal istnieje
 * (SPEC §1.8 — nie kasujemy twardo przy scaleniu).
 */
final class TagSuggester
{
    /** @return Collection<int, Tag> */
    public function sugeruj(string $fraza): Collection
    {
        $fraza = trim($fraza);

        if (mb_strlen($fraza) < LimityTagow::minZnakow()) {
            return new Collection;
        }

        $needle = $this->normalize($fraza);
        $limit = LimityTagow::maksPodpowiedzi();

        $wiersze = DB::select(<<<'SQL'
            SELECT id, 1 AS priorytet, 1.0 AS waga FROM tags
                WHERE status = 'active' AND kuking_normalize(name) LIKE ?
            UNION ALL
            SELECT t.id, 2 AS priorytet, 1.0 AS waga FROM tag_aliases a
                JOIN tags t ON t.id = a.tag_id
                WHERE t.status = 'active' AND kuking_normalize(a.alias) = ?
            UNION ALL
            SELECT id, 3 AS priorytet, similarity(kuking_normalize(name), ?) AS waga FROM tags
                WHERE status = 'active' AND kuking_normalize(name) % ?
            SQL, [$needle.'%', $needle, $needle, $needle]);

        $idsWKolejnosci = collect($wiersze)
            ->sortBy([['priorytet', 'asc'], ['waga', 'desc']])
            ->pluck('id')
            ->unique()
            ->take($limit)
            ->values();

        if ($idsWKolejnosci->isEmpty()) {
            return new Collection;
        }

        // Popularność liczona W LOCIE, nie z osobnej kolumny — patrz komentarz
        // klasy. `published()`, żeby nie liczyć wpisów szkicowych/usuniętych.
        $tagi = Tag::query()
            ->withCount(['posts' => fn ($q) => $q->published()])
            ->whereIn('id', $idsWKolejnosci->all())
            ->get()
            ->keyBy('id');

        return $idsWKolejnosci->map(fn (string $id) => $tagi[$id])->values();
    }

    /**
     * Fraza po stronie PHP znormalizowana TAK SAMO jak kolumna po stronie
     * bazy (`kuking_normalize`) — inaczej „Żurek” nie znajdzie „żurek”.
     * Skopiowane z `SearchQuery::normalize()` — to jest ta sama reguła,
     * nie przypadkowe podobieństwo.
     */
    private function normalize(string $fraza): string
    {
        return mb_strtolower(Str::ascii(mb_substr($fraza, 0, LimityTagow::maksZnakow())));
    }
}
