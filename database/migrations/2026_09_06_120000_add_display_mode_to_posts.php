<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sposób wyświetlania zdjęć we wpisie (issue #92).
 *
 * DLACZEGO KOLUMNA, A NIE POLE W `metadata`
 * To jest decyzja autora o TREŚCI, a nie szczegół techniczny: od niej zależy,
 * co zobaczy każdy czytelnik. Kolumna z ograniczeniem CHECK znaczy, że baza
 * nie przyjmie trybu, którego widok nie umie narysować — a walidacja w PHP
 * jest wtedy dodatkiem, nie jedyną bramką (AGENTS.md §6).
 *
 * DOMYŚLNA WARTOŚĆ ZAMIAST MIGRACJI DANYCH
 * `DEFAULT 'normal'` wypełnia wszystkie istniejące wiersze w jednym kroku
 * i bez przepisywania tabeli (PostgreSQL 11+ trzyma wartość domyślną
 * w katalogu, nie w wierszach). Wpis zapisany przed tą zmianą wyświetla się
 * dokładnie jak dotąd — to jest kryterium akceptacji z issue, nie życzenie.
 *
 * ROLLBACK
 * `down()` zdejmuje CHECK i kasuje kolumnę. Traci się wyłącznie wybór autora
 * (wszystko wraca do układu „zwykle"), żadne zdjęcie ani wpis nie ginie.
 * Bezpieczne do wykonania na produkcji w trakcie awarii:
 *
 *     php artisan migrate:rollback --step=1
 *
 * Kolejność w `down()` jest odwrotna do `up()` — najpierw ograniczenie,
 * potem kolumna — bo skasowanie kolumny z zależnym CHECK-iem wymagałoby
 * CASCADE, a CASCADE w rollbacku to narzędzie, które kasuje więcej,
 * niż się prosiło.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('display_mode', 20)->default('normal');
        });

        if ($this->isPostgres()) {
            DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_display_mode_check CHECK (display_mode IN ('normal','carousel','collage'))");
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_display_mode_check');
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('display_mode');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
