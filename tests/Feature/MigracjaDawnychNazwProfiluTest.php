<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja tabeli dawnych nazw profilu: kształt, ograniczenia i rollback.
 */
class MigracjaDawnychNazwProfiluTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_10_01_100000_create_profile_username_redirects_table.php');
    }

    public function test_tabela_ma_klucz_na_nazwie_ograniczenia_i_indeks_po_osobie(): void
    {
        $this->assertTrue(Schema::hasTable('profile_username_redirects'));

        $pk = DB::selectOne("SELECT a.attname AS kolumna FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = 'profile_username_redirects'::regclass AND i.indisprimary");
        $this->assertSame('username', $pk->kolumna);

        $ograniczenia = collect(DB::select("SELECT conname, contype, confdeltype FROM pg_constraint WHERE conrelid = 'profile_username_redirects'::regclass"))->keyBy('conname');
        $this->assertTrue($ograniczenia->has('profile_username_redirects_format_check'));
        $fk = $ograniczenia->first(fn ($o) => $o->contype === 'f');
        $this->assertNotNull($fk);
        $this->assertSame('c', $fk->confdeltype, 'Klucz obcy do users ma być ON DELETE CASCADE.');

        $this->assertNotNull(DB::selectOne("SELECT 1 AS jest FROM pg_indexes WHERE tablename = 'profile_username_redirects' AND indexname = 'profile_username_redirects_user_idx'"));
    }

    public function test_down_zdejmuje_tabele_a_up_odtwarza_ja_z_ograniczeniami(): void
    {
        $this->migracja()->down();
        $this->assertFalse(Schema::hasTable('profile_username_redirects'));

        $this->migracja()->up();
        $this->assertTrue(Schema::hasTable('profile_username_redirects'));

        $u = $this->user('basia');
        DB::table('profile_username_redirects')->insert(['username' => 'dawna', 'user_id' => $u->getKey(), 'created_at' => now()]);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('profile_username_redirects')->insert(['username' => 'Wielka', 'user_id' => $u->getKey(), 'created_at' => now()]));
    }
}
