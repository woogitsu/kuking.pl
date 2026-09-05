<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publiczna twarz użytkownika: @username, imię wyświetlane, bio, avatar.
 *
 * `username` jest ograniczony do [a-zA-Z0-9_] mimo że produkt jest polski —
 * bo trafia do URL-a (/@basia) i do e-maili. Imię i nazwisko z polskimi
 * znakami żyje w `display_name`, które nie musi być unikalne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();

            $table->string('username', 40)->unique();
            $table->string('display_name', 100);
            $table->string('bio', 500)->nullable();
            $table->foreignUuid('avatar_media_id')->nullable()->constrained('media')->nullOnDelete();

            // Skąd jest, np. "Podkarpacie". Nie zbieramy dokładnego adresu
            // (docs/SECURITY_PRIVACY_LEGAL.md — minimalizacja danych).
            $table->string('region', 80)->nullable();

            // "Na tym się znam" — 2-3 słowa od użytkownika. Paliwo dla poczucia
            // kompetencji i dla podpowiedzi kogo obserwować.
            $table->string('speciality', 120)->nullable();

            $table->timestampsTz();
        });

        if ($this->isPostgres()) {
            DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_username_check CHECK (username ~ '^[a-zA-Z0-9_]{3,40}$')");
            DB::statement('CREATE INDEX profiles_username_trgm_idx ON profiles USING gin (username gin_trgm_ops)');
            DB::statement('CREATE INDEX profiles_display_name_trgm_idx ON profiles USING gin (display_name gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
