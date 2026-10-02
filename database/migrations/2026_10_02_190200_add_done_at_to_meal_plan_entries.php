<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2593 (V2): prywatne, odwracalne oznaczenie „Zrobione” przy pozycji planera.
 *
 * `done_at` NULL = pozycja nieoznaczona (stan domyślny, także dla wszystkich
 * istniejących wierszy). Wartość to chwila oznaczenia i zarazem znacznik
 * wersji stanu: formularz z innej karty niesie ją ze sobą, więc stare
 * „Cofnij oznaczenie” nie odwraca nowszej decyzji.
 *
 * To NIE jest wykonanie w rozumieniu D-310: nie tworzy `cooked_events`,
 * powiadomień ani statystyk.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedna pozycja jest oznaczona.
 * Usunięcie kolumny po cichu skasowałoby świadomą decyzję człowieka
 * (niedomyślny stan). Na świeżej bazie albo bez oznaczeń przechodzi bez pytania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->timestampTz('done_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('meal_plan_entries', 'done_at')) {
            return;
        }

        $oznaczone = DB::table('meal_plan_entries')->whereNotNull('done_at')->count();
        if ($oznaczone > 0) {
            throw new RuntimeException(
                "Cofnięcie tej migracji skasowałoby oznaczenia „Zrobione” przy {$oznaczone} pozycjach planera ludzi. "
                .'Jeśli naprawdę trzeba: zrób kopię (pg_dump -t meal_plan_entries), '
                .'wyczyść kolumnę ręcznie (UPDATE meal_plan_entries SET done_at = NULL) i uruchom rollback jeszcze raz.',
            );
        }

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->dropColumn('done_at');
        });
    }
};
