<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SygnalyPwaRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_usuwa_tylko_sygnaly_pwa_i_zachowuje_odmowe(): void
    {
        $user = $this->user('rollback_pwa', ['pwa_prompt_state' => 'dismissed']);
        $signals = app(ZapiszSygnal::class);
        $signals->handle($user, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => false]);
        foreach (['pwa_prompt_shown', 'pwa_install_requested', 'pwa_prompt_dismissed', 'pwa_installed'] as $name) {
            $signals->handle($user, $name);
        }
        $this->assertDatabaseCount('product_signals', 5);
        $migration = require database_path('migrations/2026_09_16_210000_add_pwa_product_signals.php');

        try {
            $migration->down();
            $this->assertDatabaseCount('product_signals', 1);
            $this->assertDatabaseHas('product_signals', ['signal_name' => 'search_performed']);
            $this->assertSame('dismissed', $user->refresh()->pwa_prompt_state);

            try {
                DB::transaction(fn () => ProductSignal::create([
                    'user_id' => $user->id, 'signal_name' => 'pwa_installed', 'properties' => [],
                ]));
                $this->fail('Cofnięty CHECK musi odrzucić sygnał PWA.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        } finally {
            $migration->up();
        }

        $signals->handle($user, ZapiszSygnal::PWA_INSTALLED);
        $this->assertDatabaseHas('product_signals', ['user_id' => $user->id, 'signal_name' => 'pwa_installed']);
        $this->assertSame('dismissed', $user->refresh()->pwa_prompt_state);
    }
}
