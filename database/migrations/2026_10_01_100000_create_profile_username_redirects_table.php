<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DAWNE NAZWY PROFILU (decyzja właściciela z 1.10.2026, wiersz w D-333).
 *
 * Adres `/@nazwa` trafia na wydrukowane karty z kodem QR, do SMS-ów i zakładek.
 * Po zmianie nazwy w ustawieniach dawny adres odpowiadałby 404, więc każda
 * taka karta przestawała działać. Tabela jest odpowiednikiem
 * `recipe_slug_redirects` dla ludzi — z jedną różnicą: wiersz wskazuje OSOBĘ
 * (`user_id`), a nie nazwę docelową. Dzięki temu łańcuch A → B → C nie ma
 * pętli ani wiszących przekierowań: i A, i B prowadzą do tego, co osoba ma
 * dziś, a powrót do dawnej nazwy po prostu kasuje wiersz.
 *
 * - `username` jest kluczem głównym, ZAWSZE małymi literami (CHECK): adres
 *   profilu nie rozróżnia wielkości liter (`lower(username)` w
 *   `profiles_username_lower_unique`), więc dwie osoby nie mogą mieć tej samej
 *   dawnej nazwy w dwóch pisowniach. Ten sam wzór znaków i długości co CHECK
 *   w `profiles`.
 * - `user_id` → `users` `ON DELETE CASCADE`: twarde skasowanie konta zabiera
 *   dawne nazwy. Wymazanie konta (status `erased`) kasuje je jawnie w
 *   `EraseAccountData` — dawna nazwa jest daną osobową (RODO art. 17).
 * - Indeks po `user_id` obsługuje kasowanie przy wymazaniu i kaskadę.
 *
 * ROLLBACK: `down()` zdejmuje tabelę. To nie jest wartość semantyczna w sensie
 * D-088 (zgoda, zakres usunięcia, widoczność) — niczego groźnego nie
 * przywraca. Cena jest jedna i jawna: dawne adresy przestają przekierowywać,
 * a karty z kodem QR sprzed zmiany nazwy wracają do 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_username_redirects', function (Blueprint $table): void {
            $table->string('username', 40)->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id', 'profile_username_redirects_user_idx');
        });

        DB::statement('ALTER TABLE profile_username_redirects ADD CONSTRAINT profile_username_redirects_format_check CHECK (username ~ \'^[a-z0-9_]{3,40}$\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_username_redirects');
    }
};
