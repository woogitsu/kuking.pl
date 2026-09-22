<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->timestampTz('body_removed_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE comments IN ACCESS EXCLUSIVE MODE');
            if (DB::table('comments')->whereNotNull('body_removed_at')->exists()) {
                throw new RuntimeException('Nie można cofnąć oznaczeń usuniętych komentarzy: istnieją zachowane ślady rozmów. Wycofaj kod bez cofania tej migracji.');
            }
            Schema::table('comments', function (Blueprint $table): void {
                $table->dropColumn('body_removed_at');
            });
        });
    }
};
