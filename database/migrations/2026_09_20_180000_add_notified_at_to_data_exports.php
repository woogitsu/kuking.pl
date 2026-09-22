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
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->timestampTz('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        // Utrata znacznika po down/up pozwoliłaby wysłać ten sam list ponownie.
        if (DB::table('data_exports')->whereNotNull('notified_at')->exists()) {
            throw new RuntimeException('Zachowaj kopię data_exports.notified_at i zakończ zadania NotifyUserExportReady przed ręcznym wycofaniem tej kolumny.');
        }

        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
