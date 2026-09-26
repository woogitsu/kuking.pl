<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `down()` licznika budżetu modelu odmawia, gdy w tym miesiącu są wydatki
 * (D-088, D-297) — i przechodzi bez pytania, gdy nie ma czego stracić.
 *
 * @bez-kontroli-dodatniej Plik nie asertuje na treści źródła: wczytuje migrację przez require i mierzy jej zachowanie, a kontrola dodatnia stoi w tym samym pliku.
 */
final class CofniecieBudzetuAiOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_BUDZET_AI';

    protected function tearDown(): void
    {
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_26_100100_create_ai_budzet_dzienny_table.php');
    }

    public function test_odmawia_przy_wydatkach_w_biezacym_miesiacu(): void
    {
        DB::table('ai_budzet_dzienny')->insert(['dzien' => Czas::dzisiajData(), 'wydano_mikrousd' => 1_250_000, 'created_at' => now(), 'updated_at' => now()]);

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie wyzerowało licznik wydatków bez pytania.');
        $this->assertStringContainsString('1,25 USD', $odmowa->getMessage());
        $this->assertStringContainsString('OPENAI_IMPORT_KEY', $odmowa->getMessage());
        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());
        $this->assertTrue(Schema::hasTable('ai_budzet_dzienny'));
    }

    public function test_kontrola_dodatnia_bez_wydatkow_w_miesiacu_cofa_sie_bez_pytania(): void
    {
        // Wydatek z poprzedniego roku nie blokuje.
        DB::table('ai_budzet_dzienny')->insert(['dzien' => '2025-01-15', 'wydano_mikrousd' => 9_000_000, 'created_at' => now(), 'updated_at' => now()]);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('ai_budzet_dzienny'));
        $this->migracja()->up();
    }

    public function test_zgoda_wypowiedziana_wprost_przepuszcza(): void
    {
        DB::table('ai_budzet_dzienny')->insert(['dzien' => Czas::dzisiajData(), 'wydano_mikrousd' => 1, 'created_at' => now(), 'updated_at' => now()]);
        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('ai_budzet_dzienny'));
        $this->migracja()->up();
    }
}
