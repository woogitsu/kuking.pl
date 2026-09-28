<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trwały ślad każdej próby alarmu od człowieka (#2169), bez treści i adresu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_urgent_alarm_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('state', 16);
            $table->string('failure_kind', 24)->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('retried_at')->nullable();
            $table->index(['state', 'queued_at'], 'human_alarm_state_queued_idx');
            $table->index(['report_id', 'queued_at'], 'human_alarm_report_queued_idx');
        });

        DB::statement("ALTER TABLE human_urgent_alarm_attempts ADD CONSTRAINT human_alarm_state_check CHECK (state IN ('queued', 'started', 'accepted', 'rejected', 'uncertain', 'blocked', 'retried'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('human_urgent_alarm_attempts')) {
            return;
        }

        $ile = DB::table('human_urgent_alarm_attempts')->count();
        if ($ile > 0) {
            throw new RuntimeException('Cofnięcie usunęłoby '.$ile.' trwałych śladów prób pilnego alarmu od człowieka. Najpierw wyjaśnij stan zadań i wykonaj osobny, zatwierdzony plan danych.');
        }

        Schema::drop('human_urgent_alarm_attempts');
    }
};
