<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Odzyskiwanie\UsunZeszyt;
use App\Models\Collection;
use App\Models\DeletedCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `deleted_collections` nie kasuje po cichu czyichś kopii
 * odzyskania usuniętych zeszytów (#2567, AGENTS.md §6 i D-088). Odmowa jest
 * WĄSKA: na pustej tabeli i przy samych przedawnionych kopiach cofnięcie
 * przechodzi (kontrola dodatnia), przy kopiach w oknie odzyskania odmawia
 * i mówi, co zrobić.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli i przy przedawnionych), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeUsunietychZeszytowTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_03_160000_create_deleted_collections_table.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_kopie_w_oknie_odzyskania(): void
    {
        $this->usunZeszyt();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Liczba kopii w oknie odzyskania, które znikną: 1.');

        try {
            $this->migracja()->down();
        } finally {
            $this->assertTrue(Schema::hasTable('deleted_collections'));
            $this->assertSame(1, DeletedCollection::query()->count());
        }
    }

    public function test_przechodzi_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('deleted_collections'));
    }

    public function test_przechodzi_gdy_wszystkie_kopie_sa_przedawnione(): void
    {
        $this->usunZeszyt();
        DeletedCollection::query()->update(['deleted_at' => now()->subDays(40)]);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('deleted_collections'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->usunZeszyt();
        putenv('KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('deleted_collections'));
    }

    private function usunZeszyt(): void
    {
        $osoba = $this->user();
        $zeszyt = Collection::create(['owner_id' => $osoba->getKey(), 'name' => 'Do usunięcia', 'visibility' => 'private']);

        app(UsunZeszyt::class)->handle($osoba, $zeszyt);

        $this->assertSame(1, DeletedCollection::query()->count(), 'Kontrola: kopia powinna powstać.');
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
