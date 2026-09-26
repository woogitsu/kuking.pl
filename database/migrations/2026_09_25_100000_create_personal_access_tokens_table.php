<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokeny osobistego dostępu Laravel Sanctum — logowanie aplikacji mobilnej
 * (D-014 zmienione decyzją właściciela 25.09.2026, D-270).
 *
 * To NIE jest kopia migracji z `vendor/laravel/sanctum`, tylko jej wersja
 * pod zasady tego schematu (AGENTS.md §6):
 *
 *  - `id` to UUID, nie `bigint`. Identyfikator tokenu stoi w jawnej części
 *    tokenu (`<id>|<sekret>`) i w adresie odwołania urządzenia na WWW —
 *    kolejny numer mówiłby każdemu, ile tokenów serwis wydał, a sąsiedni
 *    numer byłby gotowym celem do próbowania;
 *  - `tokenable_id` ma PRAWDZIWY klucz obcy do `users`, a `tokenable_type`
 *    CHECK, że wskazuje konto. Sanctum zakłada relację polimorficzną, ale
 *    w tym serwisie tokeny ma wyłącznie `User` — a relacja bez klucza obcego
 *    to wiersz, który przeżywa konto;
 *  - czas w `timestamptz`;
 *  - CHECK-i: skrót jest skrótem, nazwa urządzenia nie jest pusta.
 *
 * ROLLBACK: `down()` kasuje tabelę bez odmowy — i to jest świadome, nie
 * przeoczenie D-088. Token nie jest decyzją człowieka, którą `up()` mógłby
 * po cichu odwrócić, tylko poświadczeniem. Po `down()` + `migrate` tabela
 * wraca PUSTA, czyli każde urządzenie musi zalogować się jeszcze raz — to
 * kierunek bezpieczny (odwołanie dostępu), nie groźny. Nic nie wraca
 * w znaczeniu przeciwnym do wybranego. Pilnuje tego
 * `tests/Feature/Api/TabelaTokenowDostepuTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nazwy kolumn narzuca Sanctum (`HasApiTokens::tokens()` to
            // `morphMany(..., 'tokenable')`), więc zostają, choć jedyny
            // dopuszczalny typ to konto — patrz CHECK niżej.
            $table->string('tokenable_type', 100);

            // ON DELETE CASCADE — token bez konta jest bez sensu. Kont się tu
            // nie kasuje (anonimizuje je `EraseAccountData`, D-022), więc
            // kaskada jest drugą linią obrony; pierwszą jest jawne kasowanie
            // tokenów w `User::invalidateSessions()`.
            $table->foreignUuid('tokenable_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Nazwa urządzenia podana przy logowaniu („Telefon Ani"). Widzi ją
            // właściciel konta na liście „Urządzenia z dostępem".
            $table->string('name', 100);

            // SHA-256 sekretu tokenu, szesnastkowo. Sam sekret nie trafia
            // do bazy nigdy.
            $table->string('token', 64)->unique();

            // Uprawnienia tokenu jako JSON (Sanctum zapisuje `["*"]`).
            $table->text('abilities')->nullable();

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['tokenable_type', 'tokenable_id']);
        });

        if (! $this->isPostgres()) {
            return;
        }

        // Jedyny typ właściciela tokenu to konto. Klucz obcy wyżej wskazuje
        // `users` — gdyby Sanctum zapisało tu inny model, wiersz twierdziłby,
        // że należy do czegoś, do czego klucz obcy go nie przypina.
        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
            ADD CONSTRAINT personal_access_tokens_tokenable_type_check
            CHECK (tokenable_type = 'App\Models\User')
        SQL);

        // SKRÓT MA BYĆ SKRÓTEM. Sekret tokenu zaczyna się od `kuking_` (patrz
        // `config/sanctum.php`), więc zapisany jawnie łamie ten warunek i baza
        // go odrzuci — to bramka na jedyny błąd, którego ta tabela nie ma
        // prawa przeżyć.
        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
            ADD CONSTRAINT personal_access_tokens_token_format_check
            CHECK (token ~ '^[0-9a-f]{64}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
            ADD CONSTRAINT personal_access_tokens_name_not_blank_check
            CHECK (length(btrim(name)) > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
