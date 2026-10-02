<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2502: osobny znacznik zmian porcji odróżnia A→B→A od zwykłej zmiany kroku.
 *
 * Rollback jest bezpieczny dla świeżych wierszy. Przy aktywnej historii zmian
 * porcji odmawia: utrata znacznika dopuściłaby stare formularze do zapisu
 * składników. Poczekaj na wygaśnięcie postępu lub wyłącz synchronizację
 * świadomie w interfejsie; nie kasuj danych przy okazji migracji.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('cooking_progress', function (Blueprint $table): void {
            $table->unsignedInteger('servings_revision')->default(1);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cooking_progress ADD CONSTRAINT cooking_progress_servings_revision_check CHECK (servings_revision >= 1) NOT VALID');
            DB::statement('ALTER TABLE cooking_progress VALIDATE CONSTRAINT cooking_progress_servings_revision_check');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cooking_progress', 'servings_revision')) {
            return;
        }

        $aktywnych = DB::table('cooking_progress')
            ->where('expires_at', '>', now())
            ->where('servings_revision', '>', 1)
            ->count();

        if ($aktywnych > 0) {
            throw new RuntimeException("Cofnięcie usunie historię zmian porcji w {$aktywnych} aktywnych postępach. Poczekaj, aż wygasną, albo wyłącz synchronizację tych przepisów przed ponowieniem rollbacku.");
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cooking_progress DROP CONSTRAINT IF EXISTS cooking_progress_servings_revision_check');
        }

        Schema::table('cooking_progress', function (Blueprint $table): void {
            $table->dropColumn('servings_revision');
        });
    }
};
