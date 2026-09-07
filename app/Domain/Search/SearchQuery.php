<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ProgPodobienstwa;
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
    // PRÓG PODOBIEŃSTWA MIESZKA W `App\Support\ProgPodobienstwa`, nie tutaj.
    //
    // Do 7 września 2026 stała `SIMILARITY_THRESHOLD = 0.12` była w tym pliku
    // — i NIGDY nie była używana. Operator `%` nie przyjmuje progu jako
    // argumentu, bierze go z ustawienia sesji, którego nikt nie ustawiał,
    // czyli z domyślnego 0.3. Zmierzone: literówka „sernk" nie znajdowała
    // „Sernika babci Haliny", mimo że podobieństwo wynosiło 0.1818, czyli
    // POWYŻEJ udokumentowanego progu. Cała odporność na literówki, którą
    // obiecuje komentarz klasy, była martwa.
    //
    // Uzasadnienie wyboru `set_limit()` zamiast `similarity(...) >= ?`
    // (indeks trigramowy obsługuje `%`, nie porównanie wyniku funkcji) —
    // w komentarzu tamtej klasy.

    /**
     * Identyfikatory przepisów pasujących do frazy — CZTERY OSOBNE ZAPYTANIA
     * sklejone przez `UNION ALL`, a nie jeden warunek z `OR` (issue #116).
     *
     * DLACZEGO NIE `OR`
     * Cztery warunki na czterech różnych kolumnach (a jeden na innej tabeli)
     * połączone przez `OR` nie dają się PostgreSQL złożyć w jeden plan
     * z czterech indeksów. Planner poddawał się i skanował całe tabele —
     * przy większej liczbie kont brał `users` jako sterownik pętli, więc koszt
     * wyszukiwania rósł z liczbą KONT W SERWISIE, a nie z liczbą trafień.
     * Indeksy trigramowe dla wszystkich czterech warunków istnieją od migracji
     * `2026_09_05_001300` — problemem był kształt zapytania, nie brak indeksu.
     *
     * Rozbite na gałęzie — każda dostaje własny skan, który może pójść po
     * indeksie. Zmierzone (PostgreSQL 16.13, bufory ciepłe, mediana z 5
     * przebiegów `EXPLAIN (ANALYZE, FORMAT JSON)`, fraza „pierogi"):
     *
     *     100 kont /   200 przepisów    1,57 ms →  0,49 ms
     *    1000 kont /  2000 przepisów   14,04 ms →  3,10 ms
     *    5000 kont / 10000 przepisów   68,16 ms → 14,12 ms
     *
     * UCZCIWA UWAGA DO ISSUE: na PostgreSQL 16.13 nie odtworzyłem planu,
     * w którym `users` steruje pętlą po `recipes` — przy stałej liczbie
     * przepisów, a rosnącej liczbie kont (100 → 5000) stara wersja trzymała
     * się 13,4–14,5 ms. Odtworzyłem PRZYCZYNĘ opisaną w issue: warunek z `OR`
     * nie sięgał po żaden z indeksów na `recipes` i czytał tabelę w całości,
     * więc koszt zależał od jej ROZMIARU, a nie od liczby trafień. To jest
     * naprawione i to pilnuje test.
     *
     * `UNION ALL`, nie `UNION`: usuwanie duplikatów nie zmienia wyniku `IN`,
     * a kosztuje `HashAggregate` — na tyle, że planner wracał do skanowania
     * sekwencyjnego dwóch gałęzi (10,9 ms kontra 2,8 ms na samym zapytaniu
     * kandydatów).
     *
     * To jest zmiana PLANU, nie semantyki: zbiór pasujących przepisów jest
     * dokładnie ten sam co przy `OR`, razem z sortowaniem po podobieństwie
     * na całości wyniku. Dlatego świadomie nie ma tu limitu ani osobnej rundy
     * „najpierw tytuł, doszukaj resztę tylko gdy mało wyników" — tamto
     * zmieniałoby kolejność wyników przy nielicznych trafieniach w tytule.
     */
    private const KANDYDACI_SQL = <<<'SQL'
        SELECT id FROM recipes WHERE kuking_normalize(title) % ?
        UNION ALL
        SELECT id FROM recipes WHERE kuking_normalize(title) LIKE ?
        UNION ALL
        SELECT id FROM recipes WHERE kuking_normalize(coalesce(summary, '')) LIKE ?
        UNION ALL
        SELECT recipe_id FROM recipe_ingredients WHERE kuking_normalize(ingredient_text) LIKE ?
        SQL;

    /**
     * @param  User|null  $widz  kto szuka — potrzebny WYŁĄCZNIE do blokad
     * @return Collection<int, Recipe>
     */
    public function recipes(string $phrase, ?User $widz = null, int $limit = 20, ?int $maksMinut = null): Collection
    {
        $phrase = trim($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        // Bez tego gałąź trigramowa niżej milczy przy literówkach — patrz
        // komentarz przy zniesionej stałej wyżej.
        ProgPodobienstwa::ustaw();

        return Recipe::query()
            ->publiclyVisible()
            // Konto autora aktywne (audyt A5) — bez tego wyszukiwarka
            // wypychała przepisy osoby zawieszonej albo zbanowanej na widok
            // każdego, kto akurat wpisał trafną frazę, mimo że jej profil
            // (link pod wynikiem) dawał 403.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->tap(fn ($query) => $this->pomijajZablokowanych($query, $widz, 'recipes.author_id'))
            ->with(['author.profile', 'heroMedia'])
            // `widoczneDla($widz)` W LICZNIKU, nie gołe `withCount`.
            //
            // Karta wyniku pokazuje „Ugotowane N ×" tą samą etykietą, którą
            // pokazuje strona przepisu — a tamta liczy od dziś tylko wykonania
            // widoczne dla TEGO widza (audyt przepisów: gołe `count()` stało
            // dziesięć linijek pod galerią, która filtr miała od audytu A4,
            // i zdradzało istnienie wykonania osoby zablokowanej). Bez tej
            // samej granicy tutaj ta sama etykieta znaczyłaby dwie różne
            // rzeczy zależnie od ekranu — czyli ta klasa błędu przeniesiona
            // o jeden plik dalej, a nie zamknięta.
            //
            // Dla gościa `widoczneDla(null)` nie filtruje niczego, więc
            // liczby publiczne i dane dla wyszukiwarek zostają bez zmian.
            ->withCount(['cookedEvents' => fn ($q) => $q->widoczneDla($widz)])
            ->whereRaw('recipes.id IN ('.self::KANDYDACI_SQL.')', [
                $needle,
                '%'.$needle.'%',
                '%'.$needle.'%',
                '%'.$needle.'%',
            ])
            // Filtr „Do 30 minut" (UI kit v2, ekran 03).
            //
            // Przepis BEZ podanych czasów wypada z tego filtra, a nie wpada.
            // Brak danych nie znaczy „szybki" — obiecanie, że coś zajmie
            // pół godziny, gdy nikt tego nie zmierzył, jest gorsze niż
            // nieujęcie przepisu w wynikach.
            ->when($maksMinut !== null, fn ($query) => $query
                ->whereNotNull('prep_minutes')
                ->whereNotNull('cook_minutes')
                ->whereRaw('(prep_minutes + cook_minutes) <= ?', [$maksMinut]))
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

        ProgPodobienstwa::ustaw();

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
