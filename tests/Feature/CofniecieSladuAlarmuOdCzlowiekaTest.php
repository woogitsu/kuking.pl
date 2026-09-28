<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** #2066 / AGENTS §6: rollback nie może zapomnieć, który alarm już obsłużono. */
class CofniecieSladuAlarmuOdCzlowiekaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_28_120000_add_human_urgent_alarm_handled_at_to_reports.php');
    }

    public function test_odmawia_gdy_jest_slad_i_zachowuje_wartosc(): void
    {
        $sprawa = Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'automat_model',
            'details' => 'Kontrola cofnięcia znacznika alarmu.',
            'status' => Report::STATUS_OPEN,
        ]);
        Report::query()->whereKey($sprawa->getKey())->update(['alarm_czlowieka_obsluzony_at' => now()]);

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie skasowało ślad alarmu.');
        } catch (RuntimeException $blad) {
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW_CZLOWIEKA', $blad->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('reports', 'alarm_czlowieka_obsluzony_at'));
        $this->assertNotNull(DB::table('reports')->where('id', $sprawa->getKey())->value('alarm_czlowieka_obsluzony_at'));
    }

    public function test_pusta_kolumna_pozwala_na_cofniecie_i_ponowne_uruchomienie(): void
    {
        $migracja = $this->migracja();
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('reports', 'alarm_czlowieka_obsluzony_at'));

        $migracja->up();
        $this->assertTrue(Schema::hasColumn('reports', 'alarm_czlowieka_obsluzony_at'));
    }
}
