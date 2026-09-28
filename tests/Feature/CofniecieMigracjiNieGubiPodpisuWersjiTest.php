<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback migracji „Mojej wersji" nie zamienia cudzych wersji w przepisy
 * własne (issue #23, D-301, D-088).
 *
 * Podpis „Na podstawie przepisu…" jest przypisaniem autorstwa. Po
 * `migrate:rollback` → `migrate` kolumny wróciłyby puste, a każda wersja
 * stałaby się po cichu przepisem swojego autora. Dlatego `down()` odmawia
 * przy choćby jednej wersji — i przechodzi bez pytania na bazie bez wersji
 * (kontrola dodatnia: zablokowanie rollbacku na zawsze to błąd tej samej
 * wagi w drugą stronę).
 */
class CofniecieMigracjiNieGubiPodpisuWersjiTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_26_100000_add_forked_from_to_recipes.php';

    public function test_cofniecie_odmawia_gdy_w_bazie_jest_wersja(): void
    {
        $oryginal = app(PublishRecipe::class)->handle(
            $this->user('basiamig23'),
            ['title' => 'Bigos', 'visibility' => 'public'],
            [['text' => 'kapusta']],
            [['instruction' => 'Duś długo.']],
            publish: true,
        );
        $jan = $this->user('janmig23');
        $this->actingAs($jan)->post(route('recipes.fork', $oryginal->slug))->assertRedirect();
        $this->assertSame(1, Recipe::query()->whereNotNull('forked_at')->count(), 'Brak wersji — test mierzyłby nie to.');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że w bazie jest wersja z podpisem oryginału.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cofnięcie odmówione', $e->getMessage());
            $this->assertStringContainsString('(forked_at IS NOT NULL): 1.', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('recipes', 'forked_from_id'));
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('public.recipes_forked_from_idx') AS indeks")->indeks);
        $this->assertSame(
            $oryginal->getKey(),
            DB::table('recipes')->where('author_id', $jan->getKey())->value('forked_from_id'),
        );
    }

    public function test_cofniecie_przechodzi_na_bazie_bez_wersji_i_migracja_wraca(): void
    {
        $zapytania = [];
        DB::listen(static function (QueryExecuted $query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $blokada = array_search('LOCK TABLE recipes IN ACCESS EXCLUSIVE MODE', $zapytania, true);
        $liczenie = array_find_key($zapytania, static fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, 'forked_at'));
        $this->assertIsInt($blokada, 'Rollback nie wykonał blokady tabeli recipes.');
        $this->assertIsInt($liczenie, 'Rollback nie sprawdził, czy istnieją wersje.');
        $this->assertLessThan($liczenie, $blokada, 'Blokada musi poprzedzać sprawdzenie wersji.');
        $this->assertFalse(Schema::hasColumn('recipes', 'forked_from_id'));
        $this->assertFalse(Schema::hasColumn('recipes', 'forked_at'));
        $this->assertNull(DB::selectOne("SELECT to_regclass('public.recipes_forked_from_idx') AS indeks")->indeks);

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('recipes', 'forked_from_id'));
        $this->assertTrue(Schema::hasColumn('recipes', 'forked_at'));
    }

    public function test_baza_nie_przyjmuje_wskazania_bez_znacznika_ani_wersji_samej_siebie(): void
    {
        $przepis = Recipe::factory()->create();
        $inny = Recipe::factory()->create();

        foreach ([
            ['forked_from_id' => $inny->getKey(), 'forked_at' => null],
            ['forked_from_id' => $przepis->getKey(), 'forked_at' => now()],
        ] as $zle) {
            try {
                DB::transaction(fn () => DB::table('recipes')->where('id', $przepis->getKey())->update($zle));
                $this->fail('CHECK recipes_forked_spojny_check przepuścił: '.json_encode($zle));
            } catch (QueryException $e) {
                $this->assertStringContainsString('recipes_forked_spojny_check', $e->getMessage());
            }
        }

        // Kontrola dodatnia: poprawne wskazanie przechodzi.
        DB::table('recipes')->where('id', $przepis->getKey())->update(['forked_from_id' => $inny->getKey(), 'forked_at' => now()]);
        $this->assertSame($inny->getKey(), $przepis->fresh()->forked_from_id);
    }
}
