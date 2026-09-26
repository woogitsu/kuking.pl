<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback wspólnego zeszytu odmawia tylko wtedy, gdy jest co stracić (D-088, #1743).
 *
 * Obie migracje mają test odmowy I kontrolę dodatnią: strażnik, który
 * odmawia zawsze, blokowałby cofnięcie na świeżej bazie bez powodu.
 */
final class CofniecieMigracjiWspolnegoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    private function tabele(): object
    {
        return require database_path('migrations/2026_09_26_120000_create_collection_sharing_tables.php');
    }

    private function autorstwo(): object
    {
        return require database_path('migrations/2026_09_26_120100_add_added_by_to_collection_items.php');
    }

    private function zeszyt(): Collection
    {
        return Collection::create(['owner_id' => $this->user('halina')->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
    }

    public function test_cofniecie_tabel_odmawia_gdy_ktos_ma_dostep(): void
    {
        $zeszyt = $this->zeszyt();
        DB::table('collection_members')->insert(['collection_id' => $zeszyt->getKey(), 'user_id' => $this->user('jurek')->getKey(), 'created_at' => now()]);

        $odmowa = null;
        try {
            $this->tabele()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało członkostwa.');
        $this->assertStringContainsString('Liczba osób, które stracą dostęp do cudzego zeszytu: 1.', $odmowa->getMessage());
        $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_WSPOLDZIELENIE', $odmowa->getMessage());
        $this->assertSame(1, DB::table('collection_members')->count());
    }

    public function test_cofniecie_tabel_na_swiezej_bazie_przechodzi_bez_pytania(): void
    {
        $this->autorstwo()->down();
        $this->tabele()->down();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('collection_members'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('collection_invitations'));

        $this->tabele()->up();
        $this->autorstwo()->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('collection_members'));
    }

    public function test_cofniecie_autorstwa_odmawia_gdy_pozycje_dodal_ktos_inny(): void
    {
        $zeszyt = $this->zeszyt();
        $przepis = Recipe::factory()->create();
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'added_by_id' => $this->user('jurek')->getKey(),
            'created_at' => now(),
        ]);

        $odmowa = null;
        try {
            $this->autorstwo()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i zgubiło autorstwo pozycji.');
        $this->assertStringContainsString(': 1.', $odmowa->getMessage());
        $this->assertNotNull(DB::table('collection_items')->value('added_by_id'));
    }

    public function test_cofniecie_autorstwa_przechodzi_gdy_wszystko_dodal_wlasciciel_a_up_je_odtwarza(): void
    {
        $zeszyt = $this->zeszyt();
        $przepis = Recipe::factory()->create();
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'added_by_id' => $zeszyt->owner_id,
            'created_at' => now(),
        ]);

        $this->autorstwo()->down();
        $this->autorstwo()->up();

        // Uzupełnienie z `up()`: pozycja sprzed współdzielenia należy do właściciela.
        $this->assertSame((string) $zeszyt->owner_id, (string) DB::table('collection_items')->value('added_by_id'));
    }
}
