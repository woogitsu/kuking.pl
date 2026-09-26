<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `pantry_items` nie kasuje po cichu list „Co mam w domu”
 * (D-285, AGENTS.md §6 i D-088). Odmowa jest WĄSKA: na pustej tabeli
 * cofnięcie przechodzi (kontrola dodatnia), z danymi — odmawia i mówi,
 * co zrobić; zmienna środowiskowa pozwala świadomie przejść dalej.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeListCoMamWDomuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_26_120000_create_pantry_items_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_SPIZARNIE');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_cos_na_liscie(): void
    {
        $this->user()->pantryItems()->create(['name' => 'mąka']);

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby czyjąś listę.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba produktów, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_SPIZARNIE=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('pantry_items'));
        $this->assertSame(1, DB::table('pantry_items')->count());
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('pantry_items'));
        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_proc WHERE proname = 'kuking_rdzenie_skladnika'"));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->user()->pantryItems()->create(['name' => 'mąka']);
        putenv('KUKING_ROLLBACK_KASUJE_SPIZARNIE=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('pantry_items'));
    }

    private function migracja(): Migration
    {
        return require base_path(self::SCIEZKA);
    }
}
