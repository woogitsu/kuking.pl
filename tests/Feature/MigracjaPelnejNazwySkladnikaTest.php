<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigracjaPelnejNazwySkladnikaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_14_100000_dopasuj_slownik_skladnikow_do_formularza.php');
    }

    public function test_krotkie_dane_przechodza_down_up_bez_zmiany_a_indeksy_zostaja(): void
    {
        $row = Ingredient::findOrCreateByName('Mąka pszenna');
        $przed = $row->fresh()->getRawOriginal();
        $this->migracja()->down();
        $this->assertSame(160, $this->limit('canonical_name'));
        $this->assertSame(160, $this->limit('normalized_name'));
        $this->migracja()->up();
        $this->assertSame(240, $this->limit('canonical_name'));
        $this->assertNull($this->limit('normalized_name'));
        $this->assertSame($przed, $row->fresh()->getRawOriginal());
        $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'ingredients' AND schemaname = current_schema()"))->keyBy('indexname');
        $this->assertTrue($indexes->has('ingredients_normalized_name_unique'));
        $this->assertTrue($indexes->has('ingredients_name_trgm_idx'));
        $this->assertStringContainsString('UNIQUE', $indexes['ingredients_normalized_name_unique']->indexdef);
        $this->assertStringContainsString('USING gin', $indexes['ingredients_name_trgm_idx']->indexdef);
        $this->assertStringContainsString('kuking_normalize', $indexes['ingredients_name_trgm_idx']->indexdef);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => Ingredient::create(['canonical_name' => 'Inna nazwa', 'normalized_name' => $row->normalized_name]));
    }

    public function test_down_odmawia_dlugiej_nazwy_bez_jej_obciecia(): void
    {
        $row = Ingredient::create(['canonical_name' => str_repeat('a', 161), 'normalized_name' => 'krotka']);
        $this->odmowa($row);
    }

    public function test_down_odmawia_takze_gdy_tylko_normalizacja_jest_dluga(): void
    {
        $row = Ingredient::findOrCreateByName(str_repeat('Æ', 100));
        $this->assertSame(100, mb_strlen($row->canonical_name));
        $this->assertSame(200, mb_strlen($row->normalized_name));
        $this->odmowa($row);
    }

    private function odmowa(Ingredient $row): void
    {
        $przed = $row->fresh()->getRawOriginal();
        try {
            $this->migracja()->down();
            $this->fail('Rollback powinien odmówić zwężenia nazw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nie można cofnąć rozszerzenia słownika składników', $e->getMessage());
        }
        $this->assertSame($przed, $row->fresh()->getRawOriginal());
        $this->assertSame(240, $this->limit('canonical_name'));
        $this->assertNull($this->limit('normalized_name'));
    }

    private function limit(string $column): ?int
    {
        $value = DB::selectOne('SELECT character_maximum_length AS value FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', ['ingredients', $column])->value;

        return $value === null ? null : (int) $value;
    }
}
