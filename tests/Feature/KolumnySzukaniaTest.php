<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kolumny `*_search` — znormalizowany tekst leży w tabeli i NIE DA SIĘ go
 * rozjechać z tekstem, z którego powstał (issue #116).
 *
 * PO CO TA ZMIANA W OGÓLE BYŁA
 * Indeksy trigramowe stały wcześniej na wyrażeniu `kuking_normalize(kolumna)`
 * i były używane — to nie o brak indeksu chodziło. Chodziło o RECHECK: indeks
 * GIN dla operatora `%` jest stratny, więc PostgreSQL sprawdza każdego
 * kandydata jeszcze raz na wierszu tabeli, a przy progu 0,12 kandydatów jest
 * 35–60% tabeli. Każdy taki recheck wołał `unaccent()` od nowa. Zmierzone
 * na 10 000 kont / 40 000 przepisów: 169,7 ms → 59,1 ms, wynik identyczny.
 *
 * CZEGO TEN PLIK NIE SPRAWDZA
 * Czasu. Test wydajnościowy na bazie testowej (kilkadziesiąt wierszy) mierzyłby
 * szum, a przy okazji migałby w CI — dokładnie ten błąd, którego świadomie
 * unika `WyszukiwarkaUzywaIndeksowTest`. Liczby są w opisie PR-a i w
 * `docs/research/WYDAJNOSC.md`. Tutaj pilnujemy tego, co MOŻE się cicho
 * zepsuć: zgodności kolumny ze źródłem, tego że indeks stoi tam, gdzie pyta
 * zapytanie, i tego, że cofnięcie migracji odtwarza stan sprzed niej.
 */
class KolumnySzukaniaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_09_100000_materialize_search_columns.php',
        );
    }

    private function wymagajPostgresa(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Kolumny generowane i pg_trgm to PostgreSQL.');
        }
    }

    /** @return list<string> */
    private function indeksy(string $tabela): array
    {
        return array_map(
            fn (object $w): string => (string) $w->indexdef,
            DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', [$tabela]),
        );
    }

    public function test_kolumna_normalizuje_i_nadaza_za_zmiana_tytulu(): void
    {
        $this->wymagajPostgresa();

        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('halina')->getKey(),
            'title' => 'Żurek na zakwasie',
            'slug' => 'zurek-na-zakwasie-kolumny',
        ]);

        $this->assertSame(
            'zurek na zakwasie',
            DB::table('recipes')->where('id', $przepis->getKey())->value('title_search'),
            'Kolumna `title_search` nie zawiera znormalizowanego tytułu.',
        );

        // TO JEST SEDNO WYBORU „kolumna generowana, nie zwykła + trigger".
        // Zwykłą kolumnę dałoby się rozjechać każdym `UPDATE`, który o niej
        // zapomni — a wtedy wyszukiwarka szuka po starym tytule i człowiek
        // nie znajduje własnego przepisu. Kolumny generowanej nie da się
        // pominąć, bo nie ma do niej drogi zapisu.
        DB::table('recipes')->where('id', $przepis->getKey())->update(['title' => 'Rosół z kury']);

        $this->assertSame(
            'rosol z kury',
            DB::table('recipes')->where('id', $przepis->getKey())->value('title_search'),
            'Kolumna została w tyle za tytułem — wyszukiwarka szuka po nieaktualnym tekście.',
        );
    }

    public function test_nie_da_sie_zapisac_wlasnej_wartosci_do_kolumny_szukania(): void
    {
        $this->wymagajPostgresa();

        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('marek')->getKey(),
            'title' => 'Bigos staropolski',
            'slug' => 'bigos-staropolski-kolumny',
        ]);

        $this->expectException(QueryException::class);

        DB::table('recipes')
            ->where('id', $przepis->getKey())
            ->update(['title_search' => 'cokolwiek']);
    }

    public function test_wszystkie_kolumny_szukania_maja_swoj_indeks_gin(): void
    {
        $this->wymagajPostgresa();

        $oczekiwane = [
            'recipes' => ['title_search', 'summary_search'],
            'recipe_ingredients' => ['ingredient_text_search'],
            'profiles' => ['display_name_search', 'username_search', 'speciality_search'],
        ];

        foreach ($oczekiwane as $tabela => $kolumny) {
            $definicje = implode("\n", $this->indeksy($tabela));

            foreach ($kolumny as $kolumna) {
                $this->assertMatchesRegularExpression(
                    '/USING gin \('.preg_quote($kolumna, '/').' gin_trgm_ops\)/',
                    $definicje,
                    "Kolumna {$tabela}.{$kolumna} nie ma indeksu GIN — zapytanie "
                    .'wyszukiwarki będzie po niej skanowało całą tabelę.',
                );
            }
        }
    }

    /**
     * Zapytanie i indeks muszą stać na TYM SAMYM. Ta para rozjechała się już
     * raz (indeksy na surowych kolumnach kontra `unaccent(lower(...))`
     * w zapytaniu, migracja `2026_09_05_001300`) i wtedy każde wyszukiwanie
     * czytało tabelę w całości, nie dając po sobie żadnego objawu poza czasem.
     */
    public function test_zapytanie_wyszukiwarki_moze_pojsc_po_indeksie(): void
    {
        $this->wymagajPostgresa();

        Recipe::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'title' => 'Pierogi ruskie babci Haliny',
            'slug' => 'pierogi-ruskie-kolumny',
        ]);

        // Bez tego na kilkunastu wierszach planner wybierze skan sekwencyjny
        // i będzie miał rację — a test sprawdzałby jego kosztorys, nie to,
        // czy indeks jest w ogóle UŻYWALNY.
        DB::statement('SET enable_seqscan = off');

        $plan = collect(DB::select(
            'EXPLAIN (FORMAT TEXT) SELECT id FROM recipes WHERE title_search % ?',
            ['pierogi'],
        ))->pluck('QUERY PLAN')->implode("\n");

        DB::statement('SET enable_seqscan = on');

        $this->assertStringContainsString('recipes_title_trgm_idx', $plan, "Plan:\n{$plan}");
    }

    /**
     * Zmiana miała być wyłącznie kosztowa. Gdyby zmieniła choć jedno trafienie,
     * byłaby zmianą wyszukiwarki — a tej nie robimy przy okazji planu zapytania.
     */
    public function test_wynik_szukania_sie_nie_zmienil(): void
    {
        $this->wymagajPostgresa();

        $autor = $this->user('krystyna');

        foreach (['Żurek na zakwasie' => 'zurek-x', 'Rosół z kury' => 'rosol-x', 'Sernik babci Haliny' => 'sernik-x'] as $tytul => $slug) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => $tytul,
                'slug' => $slug,
            ]);
        }

        $szukaj = app(SearchQuery::class);

        // Bez diakrytyków, z diakrytykami, wielkimi literami i z literówką —
        // cztery obietnice, które wyszukiwarka składa w komentarzu klasy.
        $this->assertCount(1, $szukaj->recipes('zurek'));
        $this->assertCount(1, $szukaj->recipes('Żurek'));
        $this->assertCount(1, $szukaj->recipes('ROSÓŁ'));
        $this->assertCount(1, $szukaj->recipes('sernk'), 'Literówka przestała znajdować przepis.');
    }

    public function test_cofniecie_migracji_odtwarza_indeksy_na_wyrazeniu(): void
    {
        $this->wymagajPostgresa();

        $this->migracja()->down();

        $this->assertSame(
            [],
            DB::select(
                'SELECT column_name FROM information_schema.columns '
                .'WHERE table_name = ? AND column_name = ?',
                ['recipes', 'title_search'],
            ),
            'Cofnięcie zostawiło kolumnę `title_search`.',
        );

        $this->assertStringContainsString(
            'kuking_normalize',
            implode("\n", $this->indeksy('recipes')),
            'Cofnięcie nie odtworzyło indeksów na wyrażeniu — baza została '
            .'bez indeksu wyszukiwarki, czyli w stanie GORSZYM niż przed migracją.',
        );

        // Idempotencja w drugą stronę: `up()` po `down()` ma się założyć
        // jeszcze raz, bo tak chodzi `migrate:refresh` w CI.
        $this->migracja()->up();

        $this->assertNotSame(
            [],
            DB::select(
                'SELECT column_name FROM information_schema.columns '
                .'WHERE table_name = ? AND column_name = ?',
                ['recipes', 'title_search'],
            ),
            'Ponowne `up()` nie założyło kolumny.',
        );
    }
}
