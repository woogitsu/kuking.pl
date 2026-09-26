<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Questions\QuestionList;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Licznik „Czeka na odpowiedź (N)” na /pytania ma indeks częściowy (#372).
 *
 * Test NIE czyta definicji indeksu z pliku ani z `pg_indexes` — pyta planistę,
 * czy zapytanie zbudowane przez PRAWDZIWE `QuestionList::query()` potrafi
 * z niego skorzystać. Indeks z predykatem, którego zapytanie nie spełnia
 * (np. literówka w `kind`, warunek widoczności, którego zalogowany nie ma),
 * istniałby i nie pomagał — to właśnie ma tu wyjść.
 *
 * Na pustej bazie testowej planista wybrałby skan sekwencyjny, więc w obrębie
 * transakcji testu zdejmujemy konkurencyjne indeksy `posts` i wyłączamy
 * seq scan. DDL w PostgreSQL jest transakcyjny: `RefreshDatabase` cofa to
 * po teście.
 */
class IndeksPytanOpublikowanychTest extends TestCase
{
    use RefreshDatabase;

    private const INDEKS = 'posts_questions_published_idx';

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_25_200000_add_questions_published_index_to_posts.php');
    }

    /** Plan COUNT-u takiego, jaki liczą `PytaniaBezOdpowiedzi::przelicz()` i poprawka widza (`QuestionList::query(..., true)`). */
    private function planLicznika(?User $widz): string
    {
        return $this->plan(app(QuestionList::class)->query($widz, true));
    }

    /** @param  Builder<Post>  $zapytanie */
    private function plan(Builder $zapytanie): string
    {
        // To samo, co robi `->count()`: bez kolumn (także `withCount`) i bez ORDER BY.
        $licznik = $zapytanie->toBase()
            ->cloneWithout(['columns', 'orders'])
            ->cloneWithoutBindings(['select', 'order'])
            ->selectRaw('count(*) as aggregate');

        // Zostaje JEDYNY indeks `posts`: indeks pytań. Wtedy plan zawiera go
        // wtedy i tylko wtedy, gdy jego predykat pasuje do zapytania —
        // niezależnie od kolejności złączeń. PostgreSQL 18 w CI sięgał do
        // `posts` po `(author_id, id)` albo kluczu głównym, więc sam zakaz
        // skanu sekwencyjnego mierzył plan, a nie dopasowanie indeksu.
        // Wszystko w transakcji testu (RefreshDatabase) — wraca po teście.
        $inne = DB::select(
            "SELECT i.indexname, c.conname FROM pg_indexes i
             LEFT JOIN pg_constraint c ON c.conname = i.indexname AND c.conrelid = 'posts'::regclass
             WHERE i.tablename = 'posts' AND i.schemaname = current_schema() AND i.indexname <> ?",
            [self::INDEKS],
        );
        foreach ($inne as $indeks) {
            DB::statement($indeks->conname !== null
                ? 'ALTER TABLE posts DROP CONSTRAINT "'.$indeks->conname.'" CASCADE'
                : 'DROP INDEX "'.$indeks->indexname.'"');
        }
        DB::statement('SET LOCAL enable_seqscan = off');

        $wiersze = DB::select('EXPLAIN '.$licznik->toSql(), $licznik->getBindings());

        return implode("\n", array_map(fn (object $w): string => (string) $w->{'QUERY PLAN'}, $wiersze));
    }

    public function test_licznik_goscia_i_zalogowanego_moze_uzyc_indeksu_pytan(): void
    {
        config(['kuking.questions.enabled' => true]);
        Post::factory()->question()->create(['author_id' => $this->user()->id]);

        $this->assertStringContainsString(self::INDEKS, $this->planLicznika(null), 'Licznik gościa nie trafia w indeks pytań.');
        $this->assertStringContainsString(self::INDEKS, $this->planLicznika($this->user()), 'Licznik zalogowanego nie trafia w indeks pytań.');
    }

    /** Kontrola z drugiej strony: predykat `kind = 'question'` naprawdę zawęża. */
    public function test_zapytanie_o_dania_nie_uzywa_indeksu_pytan(): void
    {
        config(['kuking.questions.enabled' => true]);

        $dania = Post::query()->where('posts.kind', Post::KIND_DISH)->published()->widoczneDla(null);

        $this->assertStringNotContainsString(self::INDEKS, $this->plan($dania));
    }

    public function test_cofniecie_zdejmuje_indeks_a_ponowienie_go_przywraca(): void
    {
        $migracja = $this->migracja();
        $this->assertTrue($this->indeksJestWazny());

        $migracja->down();
        $this->assertFalse($this->indeksJestWazny());
        $migracja->down(); // drugi raz bez błędu: IF EXISTS

        $migracja->up();
        $migracja->up(); // drugi raz bez błędu: IF NOT EXISTS
        $this->assertTrue($this->indeksJestWazny());
    }

    public function test_niedokonczony_indeks_jest_budowany_od_nowa(): void
    {
        if (! DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper) {
            $this->markTestSkipped('Oznaczenie indeksu jako INVALID wymaga roli superużytkownika (CI ją ma).');
        }

        // Tak zostaje po przerwanym CREATE INDEX CONCURRENTLY: nazwa zajęta,
        // indeks INVALID. Samo IF NOT EXISTS by go przepuściło.
        DB::statement('DROP INDEX '.self::INDEKS);
        DB::statement('CREATE INDEX '.self::INDEKS.' ON posts (created_at)');
        DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = '".self::INDEKS."'::regclass");

        $this->migracja()->up();

        $this->assertTrue($this->indeksJestWazny());
        $this->assertNull(DB::selectOne(
            "SELECT 1 AS jest FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0] WHERE i.indexrelid = '".self::INDEKS."'::regclass AND a.attname = 'created_at'",
        ), 'Migracja zostawiła stary, niedokończony indeks zamiast zbudować właściwy.');
    }

    private function indeksJestWazny(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
}
