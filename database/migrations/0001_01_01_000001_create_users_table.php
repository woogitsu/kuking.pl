<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konto użytkownika — sam rdzeń logowania.
 *
 * Świadome decyzje:
 * - UUID zamiast bigint: identyfikator trafia do URL-i i eksportów, nie chcemy
 *   zdradzać, ilu mamy użytkowników ani pozwalać na zgadywanie sąsiednich ID.
 *   UUID NIE zastępuje autoryzacji — patrz Policies.
 * - Dane profilowe (username, bio, avatar) są w osobnej tabeli `profiles`,
 *   bo mają inny cykl życia i inne reguły widoczności niż dane logowania.
 * - `text_scale` na koncie, nie w cookie: ustawienie rozmiaru tekstu musi
 *   przetrwać zmianę przeglądarki i wyczyszczenie ciasteczek. Dla osoby, która
 *   raz z trudem powiększyła tekst, jego zniknięcie to koniec korzystania.
 * - `role` nie ma w referencyjnym schema_mvp.sql — dodajemy świadomie, bo bez
 *   ról nie da się zrobić panelu moderacji (docs/MODERATION.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password');

            $table->string('status', 20)->default('active');
            $table->string('role', 20)->default('user');

            $table->string('locale', 10)->default('pl');
            $table->smallInteger('text_scale')->default(100);

            // Zgoda na tygodniowy digest — osobno od konta, bo to inna podstawa
            // przetwarzania (docs/legal/COMPLIANCE.md).
            $table->boolean('wants_weekly_digest')->default(true);

            // Oświadczenie o minimalnym wieku złożone przy rejestracji.
            $table->timestampTz('age_confirmed_at')->nullable();

            // Kiedy użytkownik poprosił o usunięcie konta (status pending_delete).
            $table->timestampTz('delete_requested_at')->nullable();

            $table->timestampTz('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestampsTz();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','banned','pending_delete'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','moderator','admin'))");
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_text_scale_check CHECK (text_scale BETWEEN 90 AND 140)');
        }

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
