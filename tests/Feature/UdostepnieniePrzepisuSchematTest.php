<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeShare;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Schemat `recipe_shares` i jego rollback (#2650, AGENTS.md §6, D-088).
 *
 * Kontrola ujemna (wykonana przy pisaniu): `down()` bez
 * `upewnijSieZeWolnoKasowac()` oblewa
 * `test_rollback_odmawia_przy_udostepnieniach_i_przechodzi_na_pustej_tabeli`
 * („Rollback skasował udostępnienia bez pytania"); wyzwalacz bez warunku
 * `author_id = NEW.recipient_id` oblewa test autora jako odbiorcy.
 */
class UdostepnieniePrzepisuSchematTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_10_03_120000_create_recipe_shares_table.php';

    private function migracja(): Migration
    {
        return require base_path(self::MIGRACJA);
    }

    /** @return array{recipe_id: string, recipient_id: string, created_at: Carbon, updated_at: Carbon} */
    private function wiersz(): array
    {
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'visibility' => 'private']);

        return [
            'recipe_id' => (string) $przepis->getKey(),
            'recipient_id' => (string) $this->user('odbiorca')->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function test_jedno_udostepnienie_na_pare_przepis_odbiorca(): void
    {
        $wiersz = $this->wiersz();
        DB::table('recipe_shares')->insert($wiersz);

        $odmowa = null;

        try {
            DB::transaction(fn () => DB::table('recipe_shares')->insert($wiersz));
        } catch (QueryException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Baza przyjęła drugie udostępnienie tej samej osobie.');
        $this->assertStringContainsString('recipe_shares_recipe_recipient_unique', $odmowa->getMessage());
        $this->assertSame(1, DB::table('recipe_shares')->count());
    }

    public function test_baza_odmawia_autora_jako_odbiorcy_wlasnego_przepisu(): void
    {
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'visibility' => 'private']);

        $odmowa = null;

        try {
            DB::transaction(fn () => DB::table('recipe_shares')->insert([
                'recipe_id' => $przepis->getKey(),
                'recipient_id' => $autorka->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Wyzwalacz przepuścił autorkę jako odbiorcę jej własnego przepisu.');
        $this->assertSame('23514', $odmowa->getCode());

        // Kontrola dodatnia: inna osoba przechodzi.
        DB::table('recipe_shares')->insert([
            'recipe_id' => $przepis->getKey(),
            'recipient_id' => $this->user('inna')->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(1, DB::table('recipe_shares')->count());
    }

    public function test_klucze_osob_nie_wchodza_masowym_przypisaniem(): void
    {
        $this->expectException(MassAssignmentException::class);

        $this->mierzMasowePrzypisanieJakWProdukcji();
        (new RecipeShare)->fill(['recipe_id' => 'x', 'recipient_id' => 'y']);
    }

    public function test_usuniecie_przepisu_na_stale_kasuje_udostepnienia_kaskada(): void
    {
        $wiersz = $this->wiersz();
        DB::table('recipe_shares')->insert($wiersz);

        Recipe::withTrashed()->findOrFail($wiersz['recipe_id'])->forceDelete();

        $this->assertSame(0, DB::table('recipe_shares')->count());
    }

    public function test_rollback_odmawia_przy_udostepnieniach_i_przechodzi_na_pustej_tabeli(): void
    {
        $migracja = $this->migracja();
        DB::table('recipe_shares')->insert($this->wiersz());

        $odmowa = null;

        try {
            self::wykonajMigracje($migracja, 'down');
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Rollback skasował udostępnienia bez pytania.');
        $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1', $odmowa->getMessage());
        $this->assertSame(1, DB::table('recipe_shares')->count());

        // Wymuszenie po kopii działa.
        putenv('KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1');

        try {
            self::wykonajMigracje($migracja, 'down');
        } finally {
            putenv('KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW');
        }
        $this->assertFalse(Schema::hasTable('recipe_shares'));

        // Kontrola dodatnia: pusta tabela cofa się bez pytania i wraca.
        self::wykonajMigracje($migracja, 'up');
        $this->assertTrue(Schema::hasTable('recipe_shares'));
        self::wykonajMigracje($migracja, 'down');
        $this->assertFalse(Schema::hasTable('recipe_shares'));
        self::wykonajMigracje($migracja, 'up');
        $this->assertTrue(Schema::hasTable('recipe_shares'));
    }
}
