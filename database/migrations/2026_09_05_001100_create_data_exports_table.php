<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eksport danych użytkownika (RODO art. 15 i 20) — od MVP, nie "kiedyś".
 *
 * Generowanie idzie w tle (job), bo paczka ze zdjęciami może mieć setki MB.
 * Plik ma datę wygaśnięcia — nie trzymamy w storage kopii całego konta
 * bez końca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('object_key', 700)->nullable();
            $table->bigInteger('bytes')->nullable();

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE data_exports ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check CHECK (status IN ('queued','processing','ready','failed','expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
