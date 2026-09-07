<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use App\Support\LimityTagow;
use App\Support\ProgPodobienstwa;
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
 * KOLEJNOŚĆ GAŁĘZI TO KOLEJNOŚĆ RANKINGU Z SPEC §1.5, doprecyzowana
 * pomiarem na prawdziwym słowniku (D-026): dokładna NAZWA → dokładny ALIAS →
 * początek nazwy, od najkrótszej → podobieństwo trigramowe. Popularność
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
 *
 * DLACZEGO CZTERY GAŁĘZIE, A NIE TRZY (D-026)
 * Pierwotnie „początek nazwy" był jedną gałęzią o stałej wadze 1.0. Przy 651
 * tagach z ręcznej bazy to nie przeszkadzało; przy 1409 ze słownika owszem,
 * bo kolejność trafień brała się wtedy z FIZYCZNEJ kolejności wierszy, czyli
 * z niczego. Dwa zmierzone przykłady na wgranym słowniku:
 *
 *   - wpisane „chleb" → pierwsza podpowiedź „chlebek bananowy", sam „chleb"
 *     drugi;
 *   - wpisane „barszcz" → pierwszy „barszcz biały", mimo że słownik ma
 *     „barszcz" jako ALIAS „barszczu czerwonego", czyli wprost mówi, co to
 *     słowo w Polsce znaczy.
 *
 * Oba to ten sam błąd: dopasowanie DOKŁADNE przegrywało z dopasowaniem
 * CZĘŚCIOWYM. Stąd rozdzielenie: dokładna nazwa (waga 2.0) i dokładny alias
 * (waga 1.0) dzielą priorytet 1, dopiero potem idzie sam początek nazwy —
 * od najkrótszej, bo kto wpisał „zupa", chce najpierw „zupę", potem „zupę
 * krem", a nie „zupę z zielonego groszku".
 *
 * Ostatnim kryterium sortowania jest alfabet — nie dla estetyki, a żeby ta
 * sama fraza dawała ZAWSZE tę samą listę (UX_50_PLUS.md: przewidywalność
 * przed bogactwem; lista, która przeskakuje między naciśnięciami klawisza,
 * jest gorsza niż lista krótsza).
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

        // TA SAMA POPRAWKA CO W `SearchQuery` (7 września 2026): operator `%`
        // w trzeciej gałęzi niżej brał domyślny próg 0.3 zamiast progu, który
        // ten projekt uznał za granicę sensu (0.12).
        //
        // Tutaj skutek był mniej widoczny i dlatego groźniejszy: nazwy tagów
        // są KRÓTKIE, więc `similarity('sernik', 'sernk')` wychodzi ponad 0.5
        // i literówka trafiała mimo złego progu. Funkcja wyglądała na
        // działającą, bo testowaliśmy ją na krótkich słowach — a rozsypywała
        // się dokładnie tam, gdzie tag jest dłuższy („przepis po babci",
        // „zakwas na barszcz biały").
        ProgPodobienstwa::ustaw();

        // WAGA W PIERWSZEJ GAŁĘZI NIE JEST STAŁA — patrz komentarz klasy
        // („dokładna nazwa przed dłuższą"). Zmierzone na prawdziwym słowniku
        // (1409 tagów): przy wpisaniu „chleb" pierwszą podpowiedzią był
        // „chlebek bananowy", a „chleb" drugą.
        $wiersze = DB::select(<<<'SQL'
            SELECT id, 1 AS priorytet, 2.0 AS waga, name AS nazwa FROM tags
                WHERE status = 'active' AND kuking_normalize(name) = ?
            UNION ALL
            SELECT t.id, 1 AS priorytet, 1.0 AS waga, t.name AS nazwa FROM tag_aliases a
                JOIN tags t ON t.id = a.tag_id
                WHERE t.status = 'active' AND kuking_normalize(a.alias) = ?
            UNION ALL
            SELECT id, 2 AS priorytet, 1.0 / char_length(name) AS waga, name AS nazwa FROM tags
                WHERE status = 'active' AND kuking_normalize(name) LIKE ?
            UNION ALL
            SELECT id, 3 AS priorytet, similarity(kuking_normalize(name), ?) AS waga,
                   name AS nazwa FROM tags
                WHERE status = 'active' AND kuking_normalize(name) % ?
            SQL, [$needle, $needle, $needle.'%', $needle, $needle]);

        $idsWKolejnosci = collect($wiersze)
            // `nazwa` jako OSTATNIE kryterium: bez niego dwie nazwy o tej
            // samej długości wracały w kolejności fizycznej wierszy, czyli
            // takiej, jakiej nie ustala żaden przepis — a lista podpowiedzi,
            // która przy tym samym wpisanym słowie potrafi wyjść w innej
            // kolejności, jest dla osoby 50+ gorsza niż lista krótsza
            // (UX_50_PLUS.md: przewidywalność przed bogactwem).
            ->sortBy([['priorytet', 'asc'], ['waga', 'desc'], ['nazwa', 'asc']])
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
