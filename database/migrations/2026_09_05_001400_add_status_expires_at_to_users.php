<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Termin wygaśnięcia kary (issue #40).
 *
 * PROBLEM
 * `docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni"),
 * ale w bazie nie było gdzie zapisać, kiedy kara mija. Przy jednym moderatorze
 * (D-012, zamknięta alfa) nikt tego nie odklika ręcznie po tygodniu — więc
 * KAŻDA blokada czasowa stawała się w praktyce trwała. Playbook obiecywał coś,
 * czego system nie umiał zrobić.
 *
 * Trzy niezależne projekty — Discourse, Pixelfed, Fresns — trzymają to jako
 * datę w kolumnie, nie jako zadanie dla człowieka.
 * Źródło: `docs/research/repos/discourse-discourse.md`.
 *
 * DLACZEGO CHECK, A NIE SAMA WALIDACJA W PHP
 * `AGENTS.md` §6: ograniczenie ma być w bazie, bo walidator obchodzi drugi
 * endpoint. Tutaj chodzi o konkretną niespójność: konto `active` albo `banned`
 * z ustawionym terminem wygaśnięcia jest bez sensu i byłoby cichą pułapką dla
 * zadania przywracającego dostęp. Baza tego po prostu nie przyjmie.
 *
 * Termin dotyczy WYŁĄCZNIE statusu `suspended`:
 * - `banned` jest z definicji bezterminowy (odwołanie idzie przez #10, nie
 *   przez zegar),
 * - `pending_delete` ma własny licznik (`delete_requested_at`),
 * - `active` nie jest karą.
 *
 * Zawieszenie BEZ terminu nadal jest możliwe (kolumna zostaje NULL) — to jest
 * zawieszenie do decyzji człowieka.
 *
 * ROLLBACK
 * Bezpieczny i bezstratny dla schematu: `down()` zdejmuje CHECK i kasuje
 * kolumnę. Tracimy przy tym terminy wygaśnięcia aktywnych zawieszeń — po
 * wycofaniu wróciłby więc problem sprzed tej migracji (kara bez terminu),
 * ale żadne konto nie zmienia przez to statusu i nikt nie traci dostępu.
 * Konta zawieszone pozostają zawieszone do ręcznej decyzji moderatora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('status_expires_at')->nullable()->after('delete_requested_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_status_expires_at_check
            CHECK (status_expires_at IS NULL OR status = 'suspended')
        SQL);

        // Indeks częściowy: zadanie w harmonogramie pyta wyłącznie o zawieszenia
        // z minionym terminem. Bez `WHERE` indeks obejmowałby wszystkie konta,
        // z których zdecydowana większość ma tu NULL.
        DB::statement(<<<'SQL'
            CREATE INDEX users_status_expires_at_idx
            ON users (status_expires_at)
            WHERE status_expires_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_status_expires_at_idx');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_expires_at_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('status_expires_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
