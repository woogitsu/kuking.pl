<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Rollback `cooked_events.hide_as_memory` (F6) nie wyciąga na stronę główną
 * wykonania, które ktoś świadomie schował (D-088, wzór:
 * `CofniecieMigracjiNieWlaczaWspomnienTest`). Odmowa jest wąska: przy samych
 * wartościach domyślnych i na pustej tabeli cofnięcie przechodzi.
 */
class CofniecieMigracjiUkryciaWykonanTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_30_141500_add_hide_as_memory_to_cooked_events.php';

    public function test_cofniecie_odmawia_gdy_ktos_schowal_wykonanie(): void
    {
        $zofia = $this->user('zofia');
        $wykonanie = CookedEvent::factory()->create(['user_id' => $zofia->getKey(), 'cooked_at' => now()->subYear()]);

        // Prawdziwa droga: przycisk przy wspomnieniu, nie ręczny UPDATE.
        $this->actingAs($zofia)->post(route('wspomnienia.ukryj-wykonanie', $wykonanie))->assertRedirect();
        $this->assertTrue($wykonanie->fresh()->hide_as_memory, 'Wykonanie się nie schowało — test mierzyłby nie to.');

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że w bazie jest schowane wykonanie.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(cooked_events.hide_as_memory = true): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        // Odmowa PRZED zdjęciem kolumny — decyzja człowieka zostaje.
        $this->assertSame(1, $this->iloscKolumn());
        $this->assertTrue($wykonanie->fresh()->hide_as_memory);
    }

    public function test_cofniecie_przechodzi_na_wartosciach_domyslnych(): void
    {
        CookedEvent::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn(), 'Rollback nie przeszedł, choć nikt nic nie schował.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cooked_events' AND column_name = 'hide_as_memory'",
        ));
    }
}
