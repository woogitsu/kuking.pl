<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trwały ślad obsługi alarmu od człowieka; null pozwala ponowić awarię #2066. */
return new class extends Migration
{
    private const KOLUMNA = 'alarm_czlowieka_obsluzony_at';

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->timestampTz(self::KOLUMNA)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reports', self::KOLUMNA)) {
            return;
        }

        $ile = DB::table('reports')->whereNotNull(self::KOLUMNA)->count();

        if ($ile > 0 && ! filter_var((string) getenv('KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW_CZLOWIEKA'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException(
                'Cofnięcie skasowałoby ślad obsłużonych alarmów od człowieka ('.$ile.' spraw). '
                .'Po ponownym migrate stare zgłoszenie mogłoby ponownie wysłać list. '
                .'Jeśli świadomie akceptujesz ten skutek, ustaw KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW_CZLOWIEKA=true.',
            );
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn(self::KOLUMNA);
        });
    }
};
