<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zaufanie i bezpieczeństwo: zgłoszenia, decyzje moderatorów, audyt.
 *
 * Te tabele muszą istnieć PRZED publicznym startem — nie dlatego, że tak
 * mówi prawo (choć DSA art. 16-17 tego wymaga), ale dlatego, że pierwszy
 * spam pojawi się w tym samym tygodniu co pierwsi prawdziwi użytkownicy.
 *
 * `moderation_actions` przechowuje UZASADNIENIE decyzji, bo użytkownik ma
 * prawo wiedzieć, dlaczego jego treść zniknęła, i ma prawo się odwołać.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // NULL, gdy zgłaszający usunął konto — samo zgłoszenie zostaje,
            // bo decyzja moderacyjna musi mieć ślad.
            $table->foreignUuid('reporter_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('target_type', 30);
            $table->uuid('target_id');

            $table->string('reason', 40);
            $table->string('details', 2000)->nullable();
            $table->string('status', 20)->default('open');

            // Notatka moderatora widoczna tylko wewnętrznie.
            $table->string('resolution_note', 2000)->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->timestampsTz();
        });

        Schema::create('moderation_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('moderator_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('report_id')->nullable()->constrained('reports')->nullOnDelete();

            $table->string('target_type', 30);
            $table->uuid('target_id');

            $table->string('action', 40);
            $table->string('reason_code', 80);
            $table->string('note', 2000)->nullable();

            // Treść uzasadnienia wysłanego użytkownikowi (DSA art. 17).
            $table->string('user_message', 2000)->nullable();

            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 80)->nullable();
            $table->uuid('subject_id')->nullable();

            // Adres IP TYLKO jako hash — do wykrywania nadużyć wystarcza,
            // a nie trzymamy danych osobowych dłużej niż to konieczne.
            $table->string('ip_hash', 128)->nullable();

            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE reports ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE moderation_actions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_target_type_check CHECK (target_type IN ('user','post','recipe','comment','cooked_event'))");
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('open','triage','reviewing','resolved','rejected'))");
            DB::statement('CREATE INDEX reports_status_created_idx ON reports (status, created_at)');
            DB::statement('CREATE INDEX reports_target_idx ON reports (target_type, target_id)');
            DB::statement('CREATE INDEX moderation_actions_target_idx ON moderation_actions (target_type, target_id, created_at DESC)');
            DB::statement('CREATE INDEX audit_log_actor_idx ON audit_log (actor_id, created_at DESC)');
            DB::statement('CREATE INDEX audit_log_subject_idx ON audit_log (subject_type, subject_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('reports');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
