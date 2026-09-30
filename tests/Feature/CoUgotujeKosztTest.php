<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoUgotuje;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Co ugotuję z tego, co mam” na dużym zbiorze (audyt wydajności).
 *
 * Zmierzone przed zmianą: 500 przepisów × 7 składników i 20 produktów =
 * ok. 2,2 s na PostgreSQL 16. Koszt siedział w podzapytaniu liczącym brakujące
 * składniki: `kuking_rdzenie_skladnika(ingredient_text)` (rozbicie tekstu,
 * normalizacja, słownik form) było liczone od nowa dla KAŻDEJ linijki KAŻDEGO
 * kandydującego przepisu przy KAŻDYM żądaniu. Teraz rdzenie linijki leżą
 * w `recipe_ingredients.rdzenie` (wypełnia je wyzwalacz przy zapisie), a wstępny
 * filtr idzie po indeksie GIN `recipe_ingredients_rdzenie_gin_idx`.
 *
 * 1. ZGODNOŚĆ — wyrocznią jest DOSŁOWNIE dawne zapytanie (funkcja liczona na
 *    żywo), sklejone tu w teście. Lista i kolejność przepisów oraz brakujące
 *    linijki muszą być identyczne. Kolejność z `ORDER BY` jest całkowita
 *    (kończy się na `recipes.id`), więc porównanie jest jednoznaczne.
 * 2. KOSZT PLANU — sufit na szacunek planera zamiast czasu ścianowego, który
 *    skacze między maszynami (wzór: `ProfilAutoraZDuzaLiczbaWpisowKosztTest`).
 * 3. DRYF — zapisana kolumna musi równać się funkcji na żywo. Gdy ktoś zmieni
 *    ciało `kuking_rdzenie_skladnika()` bez przeliczenia `recipe_ingredients`,
 *    ten test to wyłapie (kolumna zwykła, nie generowana — patrz migracja).
 */
final class CoUgotujeKosztTest extends TestCase
{
    use RefreshDatabase;

    private ?\PDO $poZasiewie = null;

    /** Wycofanie transakcji nie oddaje statystyk ani stron: sprzątamy po niej. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) recipes, recipe_ingredients, pantry_items');
            $this->poZasiewie = null;
        }
    }

    public function test_lista_i_kolejnosc_sa_takie_jak_w_dawnym_zapytaniu(): void
    {
        $ja = $this->zasiej();

        $oczekiwane = $this->dawnyWynik($ja);
        $wynik = app(CoUgotuje::class)->dla($ja, 0, 500);

        $this->assertGreaterThan(50, count($oczekiwane['id']), 'Zbiór ma dawać sporo trafień, inaczej porównanie nic nie sprawdza.');
        $this->assertSame($oczekiwane['id'], $wynik['przepisy']->modelKeys(), 'Ta sama lista i kolejność przepisów co w dawnym zapytaniu.');
        $this->assertSame(
            $oczekiwane['brakuje'],
            $wynik['przepisy']->map(fn ($p): int => (int) $p->skladnikow_brakuje)->all(),
        );
        $this->assertSame($oczekiwane['brakujace'], $wynik['brakujace']);
    }

    public function test_druga_strona_wyniku_jest_dalszym_ciagiem_pierwszej(): void
    {
        $ja = $this->zasiej();
        $dawny = $this->dawnyWynik($ja)['id'];

        $pierwsza = app(CoUgotuje::class)->dla($ja, 0, 20);
        $druga = app(CoUgotuje::class)->dla($ja, 20, 20);

        $this->assertTrue($pierwsza['jest_wiecej']);
        $this->assertSame(array_slice($dawny, 0, 20), $pierwsza['przepisy']->modelKeys());
        $this->assertSame(array_slice($dawny, 20, 20), $druga['przepisy']->modelKeys());
    }

    public function test_zapytanie_nie_liczy_rdzeni_linijek_przepisu_na_zywo(): void
    {
        $ja = $this->zasiej();
        DB::statement("SET track_functions = 'all'");

        $przed = $this->wywolaniaFunkcjiRdzeni();
        $start = hrtime(true);
        app(CoUgotuje::class)->dla($ja);
        $ms = (hrtime(true) - $start) / 1e6;
        $wywolania = $this->wywolaniaFunkcjiRdzeni() - $przed;

        // Instrument sprawdzamy na dawnym zapytaniu: jeśli licznik nic nie
        // pokazuje, test wyżej przechodziłby zawsze.
        $przedDawnym = $this->wywolaniaFunkcjiRdzeni();
        $this->dawnyWynik($ja);
        $dawne = $this->wywolaniaFunkcjiRdzeni() - $przedDawnym;

        fwrite(STDERR, sprintf(
            "\n[co ugotuję, 500×7, 20 produktów] wywołania kuking_rdzenie_skladnika: teraz %d, dawne zapytanie %d; czas teraz %.0f ms\n",
            $wywolania,
            $dawne,
            $ms,
        ));

        $this->assertGreaterThan(1000, $dawne, 'Licznik wywołań działa: dawne zapytanie woła funkcję tysiące razy.');
        // Produkty są porównywane ich zapisaną kolumną generowaną (p.rdzenie),
        // a linijki przepisów — zapisaną recipe_ingredients.rdzenie: zero wywołań.
        $this->assertSame(
            0,
            $wywolania,
            'Dobór „co ugotuję" liczy rdzenie linijek przepisu funkcją na żywo zamiast czytać recipe_ingredients.rdzenie.',
        );
    }

    public function test_wstepny_filtr_moze_pojsc_po_indeksie_gin(): void
    {
        $this->zasiej();
        $waska = $this->user('waska_spizarnia');
        foreach (['szpinak', 'dynia'] as $nazwa) {
            $waska->pantryItems()->create(['name' => $nazwa]);
        }
        DB::statement('ANALYZE pantry_items');

        $zapytania = [];
        DB::listen(function ($z) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $z->sql) && str_contains($z->sql, 'skladnikow_brakuje')) {
                $zapytania[] = [$z->sql, $z->bindings];
            }
        });
        app(CoUgotuje::class)->dla($waska);
        $this->assertCount(1, $zapytania);

        // Bez tego na 3500 wierszach planer słusznie wybierze skan sekwencyjny
        // i test sprawdzałby jego kosztorys, a nie to, czy indeks jest UŻYWALNY
        // (ta sama technika: `KolumnySzukaniaTest`).
        DB::statement('SET enable_seqscan = off');
        [$sql, $parametry] = $zapytania[0];
        $plan = (string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'};

        $this->assertStringContainsString(
            'recipe_ingredients_rdzenie_gin_idx',
            $plan,
            'Wstępny filtr `rdzenie && …` nie korzysta z indeksu GIN recipe_ingredients_rdzenie_gin_idx.',
        );
    }

    private function wywolaniaFunkcjiRdzeni(): int
    {
        return (int) DB::scalar(
            "SELECT coalesce(sum(calls), 0) FROM pg_stat_xact_user_functions WHERE funcname = 'kuking_rdzenie_skladnika'",
        );
    }

    public function test_zapisane_rdzenie_rowna_sie_funkcji_na_zywo(): void
    {
        $this->zasiej();
        $ja = $this->user('drugi_do_dryfu');
        $przepis = Recipe::factory()->create(['author_id' => $ja->getKey()]);
        $linijka = $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => 'Mąka pszenna typ 500']);

        $this->assertSame(
            0,
            (int) DB::scalar('SELECT count(*) FROM recipe_ingredients WHERE rdzenie IS DISTINCT FROM public.kuking_rdzenie_skladnika(ingredient_text)'),
        );

        // Edycja tekstu przelicza rdzenie (wyzwalacz działa też na UPDATE).
        $linijka->update(['ingredient_text' => 'Masło extra']);
        $this->assertSame(
            (string) DB::scalar("SELECT public.kuking_rdzenie_skladnika('Masło extra')"),
            (string) DB::scalar('SELECT rdzenie FROM recipe_ingredients WHERE id = ?', [$linijka->getKey()]),
        );
    }

    /**
     * Dawne zapytanie — dosłownie: rdzenie linijek liczone funkcją na żywo
     * i wstępny filtr `LIKE` po trigramach.
     *
     * @return array{id: list<string>, brakuje: list<int>, brakujace: array<string, list<string>>}
     */
    private function dawnyWynik(User $ja): array
    {
        $uid = (string) $ja->getKey();
        $mam = 'EXISTS (SELECT 1 FROM pantry_items p WHERE p.user_id = ? AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text))';

        $rdzenie = collect(DB::select(
            'SELECT DISTINCT ON (p.id) CASE WHEN length(t) > 4 THEN t ELSE left(t, 3) END AS rdzen '
            .'FROM pantry_items p, unnest(p.rdzenie) AS t WHERE p.user_id = ? ORDER BY p.id, length(t) DESC, t',
            [$uid],
        ))->pluck('rdzen')->unique()->values();
        $wstepnie = implode(' OR ', array_fill(0, $rdzenie->count(), 'ri.ingredient_text_search LIKE ?'));
        $wzorce = $rdzenie->map(fn (string $r): string => '%'.$r.'%')->all();

        $wiersze = DB::select(
            "SELECT r.id, (SELECT count(*) FROM recipe_ingredients ri WHERE ri.recipe_id = r.id AND NOT {$mam}) AS brakuje
             FROM recipes r
             WHERE r.status = 'published' AND r.visibility = 'public' AND r.deleted_at IS NULL
               AND r.id IN (SELECT ri.recipe_id FROM recipe_ingredients ri WHERE ({$wstepnie}) AND {$mam})
             ORDER BY brakuje, (r.prep_minutes + r.cook_minutes) ASC NULLS LAST, r.published_at DESC, r.id",
            [$uid, ...$wzorce, $uid],
        );

        $ids = array_map(fn ($w): string => (string) $w->id, $wiersze);
        $brakujace = array_fill_keys($ids, []);
        foreach (DB::select(
            "SELECT ri.recipe_id, ri.ingredient_text FROM recipe_ingredients ri WHERE ri.recipe_id = ANY (?::uuid[]) AND NOT {$mam} ORDER BY ri.position",
            ['{'.implode(',', $ids).'}', $uid],
        ) as $l) {
            $brakujace[(string) $l->recipe_id][] = (string) $l->ingredient_text;
        }

        return [
            'id' => $ids,
            'brakuje' => array_map(fn ($w): int => (int) $w->brakuje, $wiersze),
            'brakujace' => $brakujace,
        ];
    }

    /** 500 przepisów × 7 składników, 20 produktów w spiżarni, reszta bazy pusta. */
    private function zasiej(): User
    {
        $this->poZasiewie = DB::connection()->getPdo();

        $ja = $this->user('kucharz_co_ugotuje');
        $autor = $this->user('autor_przepisow_co_ugotuje');
        $teraz = now()->toIso8601String();

        DB::insert(<<<'SQL'
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, prep_minutes, cook_minutes, created_at, updated_at)
            SELECT ?, 'Przepis ' || g, 'przepis-co-ugotuje-' || g,
                   CASE WHEN g % 11 = 0 THEN 'private' ELSE 'public' END,
                   CASE WHEN g % 13 = 0 THEN 'draft' ELSE 'published' END,
                   ?::timestamptz - make_interval(mins => g % 40),
                   CASE WHEN g % 9 = 0 THEN NULL ELSE 5 + g % 6 * 5 END,
                   CASE WHEN g % 9 = 0 THEN NULL ELSE g % 4 * 10 END,
                   ?::timestamptz, ?::timestamptz
            FROM generate_series(1, 500) AS g
            SQL, [$autor->getKey(), $teraz, $teraz, $teraz]);

        $slownik = "ARRAY['mąka pszenna','jajka','masło','mleko 3,2%','cukier puder','sól','pieprz czarny','cebula','czosnek','marchew',"
            ."'ziemniaki','pomidory','śmietana 18%','ser żółty','kefir','drożdże','proszek do pieczenia','olej rzepakowy','ryż','makaron penne',"
            ."'kapusta kiszona','kiełbasa','papryka słodka','natka pietruszki','boczek wędzony','mięso mielone','jabłka','cynamon','miód','twaróg',"
            ."'szpinak','dynia','fasola biała','groszek','śledź','kasza gryczana','bułka tarta','majeranek','liść laurowy','ogórki kiszone']";

        DB::insert(<<<SQL
            INSERT INTO recipe_ingredients (recipe_id, position, ingredient_text)
            SELECT r.id, k,
                   CASE WHEN k % 2 = 0 THEN (50 + k * 25) || ' g ' ELSE '' END
                     || ({$slownik})[1 + ((row_number() OVER (ORDER BY r.slug) * 7 + k * 13 + k * k) % 40)::int]
            FROM recipes r, generate_series(0, 6) AS k
            SQL);

        $produkty = ['mąka', 'jajka', 'masło', 'mleko', 'cukier', 'sól', 'cebula', 'czosnek', 'marchewka', 'ziemniaki',
            'pomidory', 'śmietana', 'ser żółty', 'kefir', 'ryż', 'makaron', 'papryka', 'boczek', 'jabłka', 'twaróg'];
        foreach ($produkty as $nazwa) {
            $ja->pantryItems()->create(['name' => $nazwa]);
        }

        DB::statement('ANALYZE');

        return $ja;
    }
}
