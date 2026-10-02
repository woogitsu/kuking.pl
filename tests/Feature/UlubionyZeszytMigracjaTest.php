<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracja skrótu do zeszytu (#2542): up/down, odmowa cofnięcia przy wyborach
 * ludzi (D-088) z kontrolą dodatnią i klucz obcy `ON DELETE SET NULL`.
 *
 * @bez-kontroli-dodatniej `database_path()` służy tylko do wykonania pliku migracji (`down()` i `up()` na bazie testowej); żadna asercja nie dotyczy tekstu źródła, a odmowa ma kontrolę dodatnią (cofnięcie bez wyborów przechodzi).
 */
final class UlubionyZeszytMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_10_02_190000_add_ulubiony_zeszyt_to_users.php');
    }

    private function maKolumne(): bool
    {
        return DB::selectOne(
            "SELECT 1 AS jest FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'ulubiony_zeszyt_id'",
        ) !== null;
    }

    private function zeszytZeSkrotem(): array
    {
        $basia = $this->user('basia');
        $zeszyt = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $basia->forceFill(['ulubiony_zeszyt_id' => $zeszyt->getKey()])->save();

        return [$basia, $zeszyt];
    }

    public function test_kolumna_jest_uuid_nullable_z_kluczem_obcym_set_null(): void
    {
        $kolumna = DB::selectOne(
            "SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'ulubiony_zeszyt_id'",
        );

        $this->assertSame('uuid', $kolumna->data_type);
        $this->assertSame('YES', $kolumna->is_nullable);

        $regula = DB::selectOne(
            "SELECT confdeltype FROM pg_constraint WHERE conname = 'users_ulubiony_zeszyt_fk' AND convalidated",
        );
        $this->assertNotNull($regula, 'Brak zwalidowanego klucza obcego.');
        $this->assertSame('n', $regula->confdeltype, 'Klucz obcy musi być ON DELETE SET NULL.');
    }

    public function test_usuniecie_zeszytu_zdejmuje_skrot_ale_zostawia_konto(): void
    {
        [$basia, $zeszyt] = $this->zeszytZeSkrotem();

        $zeszyt->delete();

        $this->assertNotNull($basia->fresh());
        $this->assertNull($basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_klucz_obcy_nie_pozwala_wskazac_nieistniejacego_zeszytu(): void
    {
        $basia = $this->user('basia');

        $this->expectException(QueryException::class);
        $basia->forceFill(['ulubiony_zeszyt_id' => '00000000-0000-4000-8000-000000000000'])->save();
    }

    public function test_cofniecie_odmawia_gdy_ktos_ma_skrot(): void
    {
        [$basia, $zeszyt] = $this->zeszytZeSkrotem();

        $odmowa = null;
        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i zgubiło wybór skrótu.');
        $this->assertStringContainsString('Liczba kont z wybranym skrótem do zeszytu (users.ulubiony_zeszyt_id): 1.', $odmowa->getMessage());
        $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU', $odmowa->getMessage());
        $this->assertTrue($this->maKolumne());
        $this->assertSame((string) $zeszyt->getKey(), (string) $basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_cofniecie_przechodzi_bez_wyborow_a_up_odtwarza_kolumne(): void
    {
        $this->user('zofia');

        $this->migracja()->down();
        $this->assertFalse($this->maKolumne());

        $this->migracja()->up();
        $this->assertTrue($this->maKolumne());
    }

    public function test_wymuszenie_flaga_zdejmuje_kolumne_mimo_wyborow(): void
    {
        $this->zeszytZeSkrotem();

        putenv('KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU=1');
        try {
            $this->migracja()->down();
        } finally {
            putenv('KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU');
        }

        $this->assertFalse($this->maKolumne());
        $this->migracja()->up();
        $this->assertTrue($this->maKolumne());
    }
}
