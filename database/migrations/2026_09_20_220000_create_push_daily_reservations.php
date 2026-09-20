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
        Schema::create('push_daily_reservations', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('local_date');
            $table->timestampTz('reserved_at');
            $table->primary(['user_id', 'local_date']);
            $table->index(['user_id', 'reserved_at']);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('LOCK TABLE push_daily_reservations IN ACCESS EXCLUSIVE MODE');
            // Świeża baza przechodzi; zapisanej bariery nie odtworzy up().
            if (DB::table('push_daily_reservations')->exists()) {
                throw new RuntimeException('Zachowaj tabelę push_daily_reservations przy wycofaniu kodu. Usunięcie rezerwacji odnowiłoby wykorzystane limity.');
            }
            Schema::drop('push_daily_reservations');
        });
    }
};
