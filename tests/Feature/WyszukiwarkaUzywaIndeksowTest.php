<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wyszukiwarka przepisów naprawdę może użyć indeksów trigramowych (issue #116).
 *
 * PO CO TEST NA KSZTAŁT PLANU, A NIE NA WYNIK
 * Wynik jest w obu wersjach IDENTYCZNY — to była zmiana planu wykonania, nie
 * semantyki (`SearchTest` pilnuje wyników i przeszedł bez jednej poprawki).
 * Żaden test sprawdzający, CO wyszukiwarka znajduje, nie odróżni więc wersji
 * korzystającej z indeksu od tej, która czyta całą tabelę. Widać to wyłącznie
 * w `EXPLAIN` — i tylko to rośnie z ilością danych.
 *
 * Cztery warunki na czterech kolumnach połączone przez `OR` nie dają się
 * PostgreSQL złożyć w plan z czterech indeksów: planner zbijał je w jeden
 * `Filter` na `recipes` i czytał tabelę w całości, choć wszystkie cztery
 * indeksy istnieją od migracji `2026_09_05_001300`. Rozbicie na `UNION ALL`
 * daje każdej gałęzi własny skan, który MOŻE pójść po indeksie.
 *
 * Zmierzone (PostgreSQL 16.13, bufory ciepłe, mediana z 5 przebiegów
 * `EXPLAIN (ANALYZE, FORMAT JSON)`, fraza „pierogi”):
 *
 *   100 kont / 200 przepisów      1,57 ms → 0,49 ms
 *   1000 kont / 2000 przepisów   14,04 ms → 3,10 ms
 *   5000 kont / 10000 przepisów  68,16 ms → 14,12 ms
 */
class WyszukiwarkaUzywaIndeksowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ile przepisów zasiać. Tyle, żeby tabela nie była pusta — nic więcej.
     *
     * CZEGO TU ŚWIADOMIE NIE MA: asercji „plan zawiera recipes_title_trgm_idx".
     * Napisałem ją i działała — przy 800 wierszach planner faktycznie wybierał
     * ten indeks (przy 200 i 400 wolał skan sekwencyjny i miał RACJĘ, bo na tak
     * małej tabeli indeks nie ma czego przyspieszyć). Ale ta sama asercja
     * oblewała w pełnym przebiegu zestawu, przy tych samych danych: WYBÓR
     * indeksu zależy od kosztorysu i statystyk, czyli od stanu bazy i wersji
     * PostgreSQL, a nie od kształtu naszego zapytania. Taki test migałby
     * w CI z powodów, które z tą naprawą nie mają nic wspólnego — a test,
     * który miga, jest gorszy od jego braku, bo uczy ignorowania czerwonego.
     *
     * Zostają dwie asercje STRUKTURALNE. One sprawdzają dokładnie to, co
     * zmieniliśmy: że warunki są czterema osobnymi gałęziami, z których każda
     * MOŻE pójść po indeksie. Czy pójdzie, decyduje baza — i to jest jej
     * decyzja, nie nasza. Zmierzone czasy są w komentarzu klasy wyżej.
     */
    private const PRZEPISOW = 50;

    public function test_warunki_szukania_nie_stoja_juz_w_jednym_or(): void
    {
        $this->zasiej();

        $plan = $this->plan('pierogi');

        // TO JEST WŁAŚCIWY TEST TEJ NAPRAWY i jedyny, który nie zależy od
        // kosztów ani statystyk: cztery warunki mają być CZTEREMA gałęziami
        // `Append`, każda ze swoim skanem jednej tabeli. Dopóki tak jest,
        // planner MOŻE wybrać dla każdej z nich indeks. Zbite w jeden `Filter`
        // z `OR` — nie może, niezależnie od rozmiaru tabeli.
        $galezie = $this->wezly($plan, fn (array $w): bool => ($w['Parent Relationship'] ?? null) === 'Member');

        $this->assertCount(
            4,
            $galezie,
            'Warunki szukania nie są już czterema osobnymi gałęziami zapytania — wrócił jeden `OR`.',
        );

        // Żaden filtr na przepisach nie może zawierać `OR`: to właśnie ten
        // kształt planner zbijał w pełny skan.
        foreach ($this->wezly($plan, fn (array $w): bool => isset($w['Filter'])) as $wezel) {
            $this->assertStringNotContainsString(
                ' OR ',
                (string) $wezel['Filter'],
                'Filtr w planie łączy warunki przez OR: '.$wezel['Filter'],
            );
        }
    }

    public function test_skladniki_nie_sa_szukane_dla_kazdego_przepisu_z_osobna(): void
    {
        $this->zasiej();

        $plan = $this->plan('pierogi');

        // Dawne `orWhereExists` na `recipe_ingredients` było podzapytaniem
        // SKORELOWANYM — wykonywanym raz na każdy przepis. Teraz składniki
        // przeszukiwane są jeden raz, jako gałąź `UNION ALL`.
        //
        // Świadomie NIE asercja „w planie nie ma słowa SubPlan": jeden SubPlan
        // zostaje i jest w porządku — to `withCount('cookedEvents')`, które
        // liczy wykonania przepisu i idzie po `cooked_events_recipe_idx`.
        // Asercja na samym słowie oblewałaby z powodu, który nie ma z tą
        // naprawą nic wspólnego.
        $skorelowane = $this->wezly(
            $plan,
            fn (array $w): bool => str_starts_with((string) ($w['Parent Relationship'] ?? ''), 'SubPlan'),
        );

        foreach ($skorelowane as $wezel) {
            $this->assertStringNotContainsString(
                'recipe_ingredients',
                json_encode($wezel, JSON_THROW_ON_ERROR),
                'Składniki znowu są przeszukiwane osobno dla każdego przepisu.',
            );
        }
    }

    /**
     * Węzły planu spełniające warunek — plan jest drzewem, więc schodzimy w głąb.
     *
     * @param  callable(array<string, mixed>): bool  $warunek
     * @return list<array<string, mixed>>
     */
    private function wezly(string $plan, callable $warunek): array
    {
        $drzewo = json_decode($plan, true, 512, JSON_THROW_ON_ERROR);

        $znalezione = [];

        $zejdz = function (array $wezel) use (&$zejdz, $warunek, &$znalezione): void {
            if ($warunek($wezel)) {
                $znalezione[] = $wezel;
            }

            foreach ($wezel['Plans'] ?? [] as $dziecko) {
                $zejdz($dziecko);
            }
        };

        $zejdz($drzewo[0]['Plan']);

        return $znalezione;
    }

    /** Plan zapytania, które wyszukiwarka NAPRAWDĘ wysyła. */
    private function plan(string $fraza): string
    {
        DB::enableQueryLog();
        (new SearchQuery)->recipes($fraza, null, 20);
        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();

        $glowne = collect($zapytania)->first(
            fn (array $q): bool => str_contains($q['query'], 'title_search'),
        );

        $this->assertNotNull($glowne, 'Nie znalazłem głównego zapytania wyszukiwarki.');

        return (string) collect(DB::select(
            'EXPLAIN (FORMAT JSON) '.$glowne['query'],
            $glowne['bindings'],
        ))->first()->{'QUERY PLAN'};
    }

    /**
     * Przepisy wstawiane HURTEM, z pominięciem fabryk i modeli.
     *
     * Przez `Recipe::factory()` byłoby tyleż osobnych `INSERT`-ów plus konta —
     * sekundy w teście, który patrzy wyłącznie na kształt planu.
     */
    private function zasiej(): void
    {
        $autor = $this->user('kucharz');

        $slowa = ['pierogi'];

        for ($i = 1; $i < 40; $i++) {
            $slowa[] = 'danie'.$i;
        }

        $przepisy = [];
        $skladniki = [];
        $teraz = now();

        for ($i = 0; $i < self::PRZEPISOW; $i++) {
            $id = (string) Str::uuid();
            $slowo = $slowa[$i % count($slowa)];

            $przepisy[] = [
                'id' => $id,
                'author_id' => $autor->getKey(),
                'title' => ucfirst($slowo).' numer '.$i,
                'slug' => Str::slug($slowo.'-'.$i),
                'summary' => 'Domowe '.$slowa[($i * 3) % count($slowa)].', jak u babci.',
                'status' => 'published',
                'visibility' => 'public',
                'published_at' => $teraz->copy()->subMinutes($i),
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ];

            $skladniki[] = [
                'id' => (string) Str::uuid(),
                'recipe_id' => $id,
                'position' => 0,
                'ingredient_text' => 'szklanka '.$slowa[($i * 7) % count($slowa)],
            ];
        }

        DB::table('recipes')->insert($przepisy);
        DB::table('recipe_ingredients')->insert($skladniki);

        // Bez świeżych statystyk planner widzi tabelę taką, jaka była po
        // migracji — czyli pustą — i wybiera skan sekwencyjny niezależnie od
        // kształtu zapytania. Test mierzyłby wtedy nie to, co trzeba.
        DB::statement('ANALYZE recipes');
        DB::statement('ANALYZE recipe_ingredients');
    }
}
