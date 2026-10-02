<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liczba planowanych porcji przy pozycji przepisu w planerze (V2, #2509).
 *
 * `meal_plan_entries.planned_servings numeric(5,2) NULL` — porcje, które
 * właściciel planu świadomie wybrał DLA TEGO DNIA (ten sam przepis: 2 porcje
 * w środę, 6 w niedzielę). Plan jest prywatny, więc liczba też. NULL znaczy
 * „bez wyboru”: ilości autora, tak jak dotąd — domyślnie i dla wszystkich
 * pozycji sprzed tej migracji. Bez backfillu z liczby porcji przepisu: to nie
 * jest wybór człowieka.
 *
 * To plan osoby, nie migawka składników: po zmianie przepisu otwarcie z planu
 * pokazuje jego aktualną treść przeliczoną na wybraną liczbę (istniejący
 * `WyborPorcji`, ta sama prezentacja co `?porcje=`).
 *
 * TYP `numeric(5,2)`, NIE `float` (jak `cooked_events.faktyczne_porcje`):
 * „2,5” ma wrócić jako „2,5”.
 *
 * CHECK-i (NOT VALID + VALIDATE, AGENTS.md §6):
 * - `meal_plan_entries_planned_servings_check` — 1–100, ten sam zakres, który
 *   przyjmuje `WyborPorcji` (NAJMNIEJ/NAJWIECEJ);
 * - `meal_plan_entries_planned_servings_nie_przy_wlasnym_check` — liczba nie
 *   stoi przy własnym wpisie (`label`). Celowo NIE wymaga `recipe_id`: po
 *   twardym usunięciu przepisu `ON DELETE SET NULL` zostawia wiersz bez
 *   przepisu i ten zapis nie może blokować kasowania przepisu.
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedna pozycja ma zapisaną liczbę: to
 * świadomy wybór człowieka, którego kolejny `migrate` nie odtworzy (kolumna
 * wróciłaby pusta, czyli ilości autora). Ręcznie: zapisz mapę
 * `SELECT id, planned_servings …`, wyzeruj kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK_ZAKRES = 'meal_plan_entries_planned_servings_check';

    private const CHECK_WLASNY = 'meal_plan_entries_planned_servings_nie_przy_wlasnym_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE meal_plan_entries ADD COLUMN IF NOT EXISTS planned_servings numeric(5,2) NULL');

        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK_ZAKRES]) === null) {
            DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT '.self::CHECK_ZAKRES
                .' CHECK (planned_servings IS NULL OR (planned_servings >= 1 AND planned_servings <= 100)) NOT VALID');
        }
        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK_WLASNY]) === null) {
            DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT '.self::CHECK_WLASNY
                .' CHECK (planned_servings IS NULL OR label IS NULL) NOT VALID');
        }

        DB::statement('ALTER TABLE meal_plan_entries VALIDATE CONSTRAINT '.self::CHECK_ZAKRES);
        DB::statement('ALTER TABLE meal_plan_entries VALIDATE CONSTRAINT '.self::CHECK_WLASNY);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $zapisanych = Schema::hasColumn('meal_plan_entries', 'planned_servings')
            ? DB::table('meal_plan_entries')->whereNotNull('planned_servings')->count()
            : 0;

        if ($zapisanych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba pozycji planera z zapisaną liczbą planowanych porcji '
                .'(meal_plan_entries.planned_servings IS NOT NULL): '.$zapisanych.'. '
                .'To liczba, którą właściciel planu świadomie wybrał; kolejny `migrate` odtworzyłby kolumnę pustą '
                .'(ilości autora) i po cichu ją zgubił, a podstawienie liczby porcji przepisu byłoby nieprawdą (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, planned_servings FROM meal_plan_entries WHERE planned_servings IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE meal_plan_entries DROP CONSTRAINT IF EXISTS '.self::CHECK_WLASNY);
        DB::statement('ALTER TABLE meal_plan_entries DROP CONSTRAINT IF EXISTS '.self::CHECK_ZAKRES);
        DB::statement('ALTER TABLE meal_plan_entries DROP COLUMN IF EXISTS planned_servings');
    }
};
