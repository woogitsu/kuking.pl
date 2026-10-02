<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pozycja listy zakupów przeliczona na wybraną liczbę porcji (V2, #2489).
 *
 * `shopping_list_items.scaled_servings numeric(5,2) NULL` — NULL znaczy
 * „dosłowna linia autora albo ręczny wpis” (jak dotąd, także dla wszystkich
 * istniejących pozycji). Liczba znaczy: ilość w `text` policzył Kuking z linii
 * autora na tyle porcji (istniejący `WyborPorcji`/`PrzeliczSkladnik`), więc
 * pozycja nie udaje dosłownej linii autora — także po późniejszej edycji
 * przepisu albo utracie dostępu do niego.
 *
 * TYP `numeric(5,2)`, NIE `float` (jak `cooked_events.faktyczne_porcje` i
 * `meal_plan_entries.planned_servings`): „2,5” ma wrócić jako „2,5”.
 *
 * CHECK-i (NOT VALID + VALIDATE, AGENTS.md §6):
 * - `shopping_list_items_scaled_servings_check` — 1–100, zakres `WyborPorcji`;
 * - `shopping_list_items_scaled_servings_tylko_z_przepisu_check` — liczba
 *   stoi wyłącznie przy pozycji skopiowanej z przepisu (`source = 'recipe'`).
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedna pozycja ma zapisaną liczbę:
 * usunięcie kolumny sprawiłoby, że przeliczone ilości wyglądałyby jak
 * dosłowne linie autora, czyli zmieniłoby znaczenie danych człowieka. Ręcznie:
 * zapisz mapę `SELECT id, scaled_servings …`, wyzeruj kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK_ZAKRES = 'shopping_list_items_scaled_servings_check';

    private const CHECK_ZRODLO = 'shopping_list_items_scaled_servings_tylko_z_przepisu_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE shopping_list_items ADD COLUMN IF NOT EXISTS scaled_servings numeric(5,2) NULL');

        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK_ZAKRES]) === null) {
            DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT '.self::CHECK_ZAKRES
                .' CHECK (scaled_servings IS NULL OR (scaled_servings >= 1 AND scaled_servings <= 100)) NOT VALID');
        }
        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK_ZRODLO]) === null) {
            DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT '.self::CHECK_ZRODLO
                ." CHECK (scaled_servings IS NULL OR source = 'recipe') NOT VALID");
        }

        DB::statement('ALTER TABLE shopping_list_items VALIDATE CONSTRAINT '.self::CHECK_ZAKRES);
        DB::statement('ALTER TABLE shopping_list_items VALIDATE CONSTRAINT '.self::CHECK_ZRODLO);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $zapisanych = Schema::hasColumn('shopping_list_items', 'scaled_servings')
            ? DB::table('shopping_list_items')->whereNotNull('scaled_servings')->count()
            : 0;

        if ($zapisanych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba pozycji listy zakupów z zapisanym przeliczeniem na porcje '
                .'(shopping_list_items.scaled_servings IS NOT NULL): '.$zapisanych.'. '
                .'Bez tej kolumny przeliczone ilości wyglądałyby jak dosłowne linie autora, a kolejny `migrate` '
                .'odtworzyłby kolumnę pustą i po cichu zgubił to rozróżnienie (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, scaled_servings FROM shopping_list_items WHERE scaled_servings IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE shopping_list_items DROP CONSTRAINT IF EXISTS '.self::CHECK_ZRODLO);
        DB::statement('ALTER TABLE shopping_list_items DROP CONSTRAINT IF EXISTS '.self::CHECK_ZAKRES);
        DB::statement('ALTER TABLE shopping_list_items DROP COLUMN IF EXISTS scaled_servings');
    }
};
