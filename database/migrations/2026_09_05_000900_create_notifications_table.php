<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Powiadomienia w aplikacji (bez Web Push w MVP).
 *
 * Celowo NIE używamy tabeli `notifications` z Laravel Notifications w jej
 * domyślnym kształcie (morph + kolumna `type` z nazwą klasy PHP), bo:
 *  - chcemy stabilny, czytelny `type` (np. `cooked_event.created`), który
 *    przetrwa refaktor klas,
 *  - powiadomienia trafiają do eksportu danych użytkownika i mają być
 *    zrozumiałe dla człowieka, nie tylko dla frameworka.
 *
 * Najważniejszy typ: `cooked_event.created` — "komuś wyszło z Twojego przepisu".
 * To jest moment, dla którego ludzie tu wracają.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Kto wywołał zdarzenie. NULL = powiadomienie systemowe.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 80);
            $table->jsonb('data')->default(DB::raw("'{}'::jsonb"));

            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('CREATE INDEX notifications_user_created_idx ON notifications (user_id, created_at DESC)');
            DB::statement('CREATE INDEX notifications_user_unread_idx ON notifications (user_id, created_at DESC) WHERE read_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
