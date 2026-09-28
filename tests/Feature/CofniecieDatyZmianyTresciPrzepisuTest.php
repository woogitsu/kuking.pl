<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja `recipes.tresc_zmieniona_at` (#2014, AGENTS.md §6).
 *
 * Kolumna jest `timestamptz NULL` bez DEFAULT i bez backfillu: istniejące
 * przepisy mają „nie wiemy" (`NULL`), a nie zgadniętą datę. Cofnięcie nie
 * odmawia (to wyliczony znacznik, nie decyzja człowieka — D-088) i po
 * ponownym `up()` wraca do `NULL`, czyli do stanu, w którym JSON-LD po prostu
 * pomija opcjonalne pole.
 *
 * @bez-kontroli-dodatniej Plik migracji jest ładowany wyłącznie po to, by wykonać up()/down() na PostgreSQL; każda asercja mierzy stan bazy, nie tekst źródła.
 */
class CofniecieDatyZmianyTresciPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const KOLUMNA = 'tresc_zmieniona_at';

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_28_200000_add_tresc_zmieniona_at_to_recipes.php');
    }

    public function test_kolumna_jest_timestamptz_null_bez_domyslnej(): void
    {
        $kolumna = DB::selectOne(
            'SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['recipes', self::KOLUMNA],
        );

        $this->assertNotNull($kolumna, 'Brak kolumny `recipes.tresc_zmieniona_at`.');
        $this->assertSame('timestamp with time zone', $kolumna->data_type);
        $this->assertSame('YES', $kolumna->is_nullable);
        $this->assertNull($kolumna->column_default, 'Domyślna wartość byłaby zgadniętą datą zmiany treści.');

        // Wiersz założony poza `PublishRecipe` (jak przepis sprzed migracji) nie dostaje daty.
        $przepis = Recipe::factory()->create();
        $this->assertNull(DB::table('recipes')->where('id', $przepis->getKey())->value(self::KOLUMNA));
    }

    public function test_cofniecie_i_ponowne_uruchomienie_zostawia_nie_wiemy(): void
    {
        $przepis = Recipe::factory()->create();
        $przepis->forceFill([self::KOLUMNA => Carbon::parse('2026-09-05 10:00:00', 'UTC')])->save();

        $migracja = $this->migracja();
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('recipes', self::KOLUMNA));
        $this->assertTrue(DB::table('recipes')->where('id', $przepis->getKey())->exists(), 'Cofnięcie nie może ruszyć wierszy przepisów.');

        $migracja->up();
        $this->assertTrue(Schema::hasColumn('recipes', self::KOLUMNA));
        $this->assertNull(DB::table('recipes')->where('id', $przepis->getKey())->value(self::KOLUMNA));
    }
}
