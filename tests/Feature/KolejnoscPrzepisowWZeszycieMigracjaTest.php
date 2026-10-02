<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback ręcznej kolejności przepisów odmawia tylko wtedy, gdy jest co
 * stracić (D-088, #2544) — test odmowy I kontrola dodatnia.
 *
 * @bez-kontroli-dodatniej `database_path()` służy tylko do wykonania pliku migracji (`down()` i `up()` na bazie testowej); żadna asercja nie dotyczy tekstu źródła, a odmowa ma w pliku kontrolę dodatnią (cofnięcie na bazie bez ułożeń przechodzi).
 */
final class KolejnoscPrzepisowWZeszycieMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_10_03_140000_add_position_to_collection_items.php');
    }

    /** @return array{0: Collection, 1: Recipe} */
    private function pozycja(?int $miejsce): array
    {
        $zeszyt = Collection::create(['owner_id' => $this->user('halina')->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $przepis = Recipe::factory()->create();
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'created_at' => now(),
            'position' => $miejsce,
        ]);

        return [$zeszyt, $przepis];
    }

    public function test_cofniecie_odmawia_gdy_ktos_ulozyl_kolejnosc_i_niczego_nie_rusza(): void
    {
        $this->pozycja(1);

        $odmowa = null;
        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i zgubiło ręczną kolejność.');
        $this->assertStringContainsString('przepisów z pozycją: 1', $odmowa->getMessage());
        $this->assertStringContainsString('Wróć do kolejności zapisu', $odmowa->getMessage());
        $this->assertTrue(Schema::hasColumn('collection_items', 'position'));
        $this->assertSame(1, (int) DB::table('collection_items')->value('position'));
    }

    public function test_cofniecie_bez_ulozen_przechodzi_a_up_odtwarza_kolumne_ogranicznie_i_indeks(): void
    {
        $this->pozycja(null);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('collection_items', 'position'));
        $this->assertSame(1, DB::table('collection_items')->count(), 'Zapis w zeszycie zostaje.');

        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('collection_items', 'position'));
        $this->assertNotNull(DB::selectOne("SELECT 1 AS jest FROM pg_indexes WHERE indexname = 'collection_items_position_unique'"));
        $this->assertNotNull(DB::selectOne("SELECT 1 AS jest FROM pg_constraint WHERE conname = 'collection_items_position_check' AND convalidated"));
        $this->assertNull(DB::table('collection_items')->value('position'), 'Migracja nie nadaje pozycji istniejącym wierszom.');
    }

    public function test_up_mozna_powtorzyc_po_awarii(): void
    {
        $this->migracja()->up();
        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('collection_items', 'position'));
    }
}
