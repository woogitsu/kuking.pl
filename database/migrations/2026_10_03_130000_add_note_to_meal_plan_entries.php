<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2549 (V2): krótki prywatny dopisek przy pozycji z przepisem w planerze.
 *
 * `note` NULL = brak dopisku (stan domyślny, także dla wszystkich istniejących
 * wierszy). To osobna kolumna: `label` zostaje samodzielnym własnym wpisem
 * („przepis albo własny wpis”, D-310) i nie jest tu używane.
 *
 * Więzy:
 * - `meal_plan_entries_note_check` — po obcięciu białych znaków 1–80 znaków
 *   (pusty dopisek to NULL, nie pusty tekst);
 * - `meal_plan_entries_note_nie_przy_wlasnym_check` — dopisek nie stoi przy
 *   własnym wpisie (`label`). Celowo NIE wymaga `recipe_id`: po twardym
 *   usunięciu przepisu `ON DELETE SET NULL` zostawia wiersz bez przepisu,
 *   a ten zapis nie może blokować kasowania przepisu.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedna pozycja ma dopisek.
 * Usunięcie kolumny po cichu skasowałoby prywatne teksty ludzi. Na świeżej
 * bazie albo bez dopisków przechodzi bez pytania.
 */
return new class extends Migration
{
    // CHECK na istniejącej tabeli: NOT VALID, potem VALIDATE w osobnej transakcji (AGENTS.md §6).
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->string('note', 80)->nullable();
        });

        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_note_check
            CHECK (note IS NULL OR char_length(btrim(note)) BETWEEN 1 AND 80) NOT VALID');
        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_note_nie_przy_wlasnym_check
            CHECK (note IS NULL OR label IS NULL) NOT VALID');
        DB::statement('ALTER TABLE meal_plan_entries VALIDATE CONSTRAINT meal_plan_entries_note_check');
        DB::statement('ALTER TABLE meal_plan_entries VALIDATE CONSTRAINT meal_plan_entries_note_nie_przy_wlasnym_check');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('meal_plan_entries', 'note')) {
            return;
        }

        $zDopiskiem = DB::table('meal_plan_entries')->whereNotNull('note')->count();
        if ($zDopiskiem > 0) {
            throw new RuntimeException(
                "Cofnięcie tej migracji skasowałoby prywatne dopiski przy {$zDopiskiem} pozycjach planera ludzi. "
                .'Jeśli naprawdę trzeba: zrób kopię (pg_dump -t meal_plan_entries), '
                .'wyczyść kolumnę ręcznie (UPDATE meal_plan_entries SET note = NULL) i uruchom rollback jeszcze raz.',
            );
        }

        DB::statement('ALTER TABLE meal_plan_entries DROP CONSTRAINT IF EXISTS meal_plan_entries_note_nie_przy_wlasnym_check');
        DB::statement('ALTER TABLE meal_plan_entries DROP CONSTRAINT IF EXISTS meal_plan_entries_note_check');

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
