<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migracja `2026_09_30_140000_add_cooking_followup_product_signals` (F1, D-333):
 * po `up()` baza przyjmuje cztery sygnały „Jak wyszło?”, po `down()` kasuje
 * wyłącznie je (telemetria) i znów je odrzuca, a CHECK przez cały czas nosi
 * tę samą nazwę.
 */
final class SygnalyJakWyszloMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private const NOWE = [
        ZapiszSygnal::COOKING_LAST_STEP_REACHED,
        ZapiszSygnal::COOKING_LAST_STEP_COOKED,
        ZapiszSygnal::COOKING_FOLLOWUP_SHOWN,
        ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED,
    ];

    public function test_rollback_kasuje_tylko_nowe_sygnaly_i_przywraca_stary_slownik(): void
    {
        $sygnaly = app(ZapiszSygnal::class);
        $sygnaly->handle(null, ZapiszSygnal::PWA_INSTALLED);
        foreach (self::NOWE as $nazwa) {
            $sygnaly->handle(null, $nazwa);
        }
        $this->assertDatabaseCount('product_signals', 5);

        $migracja = require database_path('migrations/2026_09_30_140000_add_cooking_followup_product_signals.php');

        try {
            $migracja->down();

            $this->assertDatabaseCount('product_signals', 1);
            $this->assertDatabaseHas('product_signals', ['signal_name' => ZapiszSygnal::PWA_INSTALLED]);
            $this->assertSame(['product_signals_signal_name_check'], $this->nazwyCheckow());

            try {
                DB::transaction(fn () => ProductSignal::create(['signal_name' => ZapiszSygnal::COOKING_LAST_STEP_REACHED, 'properties' => []]));
                $this->fail('Cofnięty CHECK musi odrzucić sygnał „Jak wyszło?”.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        } finally {
            $migracja->up();
        }

        $this->assertSame(['product_signals_signal_name_check'], $this->nazwyCheckow());
        foreach (self::NOWE as $nazwa) {
            $sygnaly->handle(null, $nazwa);
        }
        $this->assertDatabaseCount('product_signals', 5);

        // KONTROLA UJEMNA: słownik nadal jest zamknięty.
        $this->expectException(QueryException::class);
        ProductSignal::create(['signal_name' => 'cooking_cos_innego', 'properties' => []]);
    }

    /** @return list<string> */
    private function nazwyCheckow(): array
    {
        return array_map(fn (object $w): string => $w->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_signals'::regclass AND conname LIKE 'product_signals_signal_name%' ORDER BY conname",
        ));
    }
}
