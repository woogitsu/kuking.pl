<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja `users.onboarding_zakonczony_at` na prawdziwym PostgreSQL (#985).
 *
 * Wykonujemy prawdziwe `down()` i `up()` tej migracji. DDL w transakcji
 * `RefreshDatabase` jest na PostgreSQL transakcyjny, więc schemat wraca
 * razem z końcem testu.
 *
 * KONTROLA UJEMNA: bez linii backfillu w `up()` oba testy czerwienieją —
 * wpis „Migracja pierwszych kroków bez backfillu" w
 * `scripts/kontrole-negatywne-alfa08.py`.
 */
class OnboardingMigracjaZnacznikaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): Migration
    {
        /** @var Migration $migracja */
        $migracja = require base_path(
            'database/migrations/2026_09_24_130000_add_onboarding_zakonczony_at_to_users.php',
        );

        return $migracja;
    }

    public function test_konto_sprzed_migracji_dostaje_znacznik_rowny_dacie_zalozenia(): void
    {
        $zalozone = Carbon::parse('2025-03-14 09:26:53', 'UTC');
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $user->forceFill(['created_at' => $zalozone])->save();

        $migracja = $this->migracja();
        // Stan sprzed migracji: kolumny nie ma wcale.
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('users', 'onboarding_zakonczony_at'));

        $migracja->up();

        $typ = DB::selectOne(
            "select data_type, is_nullable from information_schema.columns
             where table_name = 'users' and column_name = 'onboarding_zakonczony_at'",
        );
        $this->assertSame('timestamp with time zone', $typ->data_type);
        $this->assertSame('YES', $typ->is_nullable);

        $znacznik = $user->fresh()->onboarding_zakonczony_at;
        $this->assertNotNull($znacznik, 'Konto sprzed migracji dostałoby nagle przypomnienie „Dokończ pierwsze kroki”.');
        $this->assertTrue($zalozone->equalTo($znacznik));
    }

    public function test_cofniecie_i_ponowne_up_nie_wlacza_przypomnienia_nikomu(): void
    {
        $wTrakcie = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $niePrzypominaj = $this->user(null, ['onboarding_zakonczony_at' => now()->subDay()]);

        $migracja = $this->migracja();
        $migracja->down();
        $migracja->up();

        $this->assertSame(0, DB::table('users')->whereNull('onboarding_zakonczony_at')->count());
        $this->assertNull($wTrakcie->fresh()->onboardingDoDokonczenia());
        $this->assertNull($niePrzypominaj->fresh()->onboardingDoDokonczenia());

        // Nowe konto po migracji nadal startuje z pustym znacznikiem —
        // backfill dotyczy tylko wierszy istniejących w chwili `up()`.
        $nowe = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $this->assertSame('onboarding.interests', $nowe->fresh()->onboardingDoDokonczenia());
    }
}
