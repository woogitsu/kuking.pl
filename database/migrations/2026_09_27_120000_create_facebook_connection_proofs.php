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
        Schema::create('facebook_connection_proofs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('session_hash', 64);
            $table->string('facebook_id_hash', 64);
            $table->string('account_state_hash', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->unique('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE facebook_connection_proofs ADD CONSTRAINT facebook_proof_expiry_check CHECK (expires_at > created_at)');
            foreach (['token_hash', 'session_hash', 'facebook_id_hash', 'account_state_hash'] as $column) {
                DB::statement("ALTER TABLE facebook_connection_proofs ADD CONSTRAINT facebook_proof_{$column}_check CHECK ({$column} ~ '^[a-f0-9]{64}$')");
            }
        }
    }

    public function down(): void
    {
        // Pending proofs are deliberately invalidated by rollback. No lasting
        // account preference or identity is stored in this table.
        Schema::dropIfExists('facebook_connection_proofs');
    }
};
