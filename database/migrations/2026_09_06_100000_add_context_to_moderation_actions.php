<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dwie kolumny, bez których decyzji moderacyjnej NIE DA SIĘ COFNĄĆ (#65, #10).
 *
 * `previous_status` — STAN TREŚCI SPRZED DECYZJI
 *
 * `posts.status`, `recipes.status` i `comments.status` trzymają wyłącznie stan
 * bieżący. Po ukryciu widać tylko `hidden` i nie ma skąd wiedzieć, czy przed
 * decyzją był tam opublikowany przepis, czy prywatny szkic. Przywracanie „na
 * sztywno do `published`" upubliczniłoby cudzy szkic — treść, której autor
 * nigdy nikomu nie pokazał. To jest wyciek, nie drobiazg.
 *
 * DLACZEGO TUTAJ, A NIE W `posts`/`recipes`/`comments`
 *
 * Rozważaliśmy kolumnę `status_before_moderation` na każdej z trzech tabel
 * z treścią. Odrzucone z trzech powodów:
 *
 *  1. Trzy kolumny zamiast jednej, a każda ma sens WYŁĄCZNIE wtedy, gdy wiersz
 *     jest akurat ukryty — czyli prawie zawsze jest pusta i prawie zawsze myli.
 *  2. Stan sprzed decyzji jest faktem O DECYZJI, nie o treści. Tu leży już
 *     `reason_code`, `note` i `user_message` z tego samego powodu.
 *  3. Przy dwóch ukryciach pod rząd kolumna na treści zna tylko ostatnie.
 *     Log moderacji zna każde — a przy odwołaniu (DSA art. 17) liczy się
 *     historia, nie migawka.
 *
 * Indeks `moderation_actions_target_idx (target_type, target_id, created_at DESC)`
 * już istnieje, więc „ostatnia decyzja dla tej treści" to jedno sięgnięcie.
 *
 * `subject_user_id` — KOGO TA DECYZJA DOTKNĘŁA
 *
 * Log wiedział, jaką treść ruszono, ale nie wiedział, komu. Autora dawało się
 * odtworzyć z treści — dopóki treść istniała. Po `remove` (soft delete) i po
 * usunięciu konta pytanie „czyja to była decyzja" zostawało bez odpowiedzi,
 * a odwołanie musi umieć sprawdzić, że składa je TA osoba, której dotyczy —
 * UUID w adresie nie jest autoryzacją (AGENTS.md §7).
 *
 * ROLLBACK
 * `down()` kasuje obie kolumny. Bezpieczne: nic poza ścieżką przywracania
 * i odwołań ich nie czyta, a te dwie rzeczy znikają razem z tą migracją
 * (`appeals` ma własną, późniejszą migrację i cofa się pierwsza).
 * Cena cofnięcia: tracimy zapisany stan sprzed ukrycia dla treści już
 * ukrytych — po ponownym wdrożeniu przywracanie wróci do bezpiecznego
 * domyślnego zachowania (`RestoreContent`), nie do złego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table): void {
            $table->string('previous_status', 20)->nullable()->after('action');
            $table->foreignUuid('subject_user_id')->nullable()->after('target_id')
                ->constrained('users')->nullOnDelete();
        });

        if ($this->isPostgres()) {
            DB::statement('CREATE INDEX moderation_actions_subject_idx ON moderation_actions (subject_user_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS moderation_actions_subject_idx');
        }

        Schema::table('moderation_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subject_user_id');
            $table->dropColumn('previous_status');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
