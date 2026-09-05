<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Graf społeczny: kto kogo obserwuje i kto kogo zablokował.
 *
 * Reguła domenowa: BLOKADA MA PIERWSZEŃSTWO PRZED OBSERWOWANIEM.
 * Zablokowanie kasuje relacje follow w obie strony (patrz App\Domain\Social).
 * Klucz główny złożony zamiast sztucznego id — relacja jest tożsamością.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table): void {
            $table->foreignUuid('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['follower_id', 'followed_id']);
            $table->index(['followed_id', 'created_at'], 'follows_followed_idx');
        });

        Schema::create('blocks', function (Blueprint $table): void {
            $table->foreignUuid('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['blocker_id', 'blocked_id']);
            $table->index(['blocked_id'], 'blocks_blocked_idx');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE follows ADD CONSTRAINT follows_no_self_check CHECK (follower_id <> followed_id)');
            DB::statement('ALTER TABLE blocks ADD CONSTRAINT blocks_no_self_check CHECK (blocker_id <> blocked_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('follows');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
