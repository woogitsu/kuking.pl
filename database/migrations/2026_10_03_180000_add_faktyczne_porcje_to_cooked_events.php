<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prywatna liczba faktycznie ugotowanych porcji przy własnym „Ugotowałem"
 * (issue #2540, decyzja właściciela z 2.10.2026).
 *
 * `cooked_events.faktyczne_porcje numeric(5,2) NULL` — liczba porcji, którą
 * kucharz świadomie podał o TEJ próbie (przepis autora na cztery, próba na
 * osiem). Wyłącznie informacja dla samego kucharza: nie wchodzi do publicznej
 * karty, profilu, powiadomienia, SEO ani żadnego zbiorczego wyliczenia, nie
 * zmienia przepisu, jego wersji ani `actual_minutes`.
 *
 * TYP `numeric(5,2)`, NIE `float`: człowiek wpisuje „2,5" albo „0,75" i ma
 * dostać z powrotem dokładnie to. Dwa miejsca po przecinku to jawna
 * precyzja (jak `cooking_progress.servings` i `recipe_serving_preferences`);
 * `numeric(5,2)` mieści do 999,99, a CHECK zawęża do 0,5–100.
 *
 * `NULL` znaczy „nie podano" — domyślnie i dla wszystkich wykonań sprzed tej
 * migracji. Bez backfillu z liczby porcji przepisu: to nie dowód, ile
 * faktycznie ugotowano.
 *
 * `ADD COLUMN … NULL` bez DEFAULT zmienia sam katalog (bez przepisania
 * tabeli); CHECK idzie przez `NOT VALID` + `VALIDATE` (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedno wykonanie ma zapisaną liczbę:
 * to świadoma deklaracja człowieka, której kolejny `migrate` nie odtworzy.
 * Ręcznie: zapisz mapę `SELECT id, faktyczne_porcje …`, potem wyzeruj
 * kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK = 'cooked_events_faktyczne_porcje_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS faktyczne_porcje numeric(5,2) NULL');

        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK]) === null) {
            DB::statement('ALTER TABLE cooked_events ADD CONSTRAINT '.self::CHECK
                .' CHECK (faktyczne_porcje IS NULL OR (faktyczne_porcje >= 0.5 AND faktyczne_porcje <= 100)) NOT VALID');
        }

        DB::statement('ALTER TABLE cooked_events VALIDATE CONSTRAINT '.self::CHECK);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Po przerwanym `up()` kolumny może nie być: wtedy nie ma czego liczyć.
        $zapisanych = Schema::hasColumn('cooked_events', 'faktyczne_porcje')
            ? DB::table('cooked_events')->whereNotNull('faktyczne_porcje')->count()
            : 0;

        if ($zapisanych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań z zapisaną liczbą faktycznych porcji '
                .'(cooked_events.faktyczne_porcje IS NOT NULL): '.$zapisanych.'. '
                .'To liczba, którą kucharz świadomie podał; kolejny `migrate` odtworzyłby kolumnę pustą '
                .'i po cichu ją zgubił, a odtworzenie z liczby porcji przepisu byłoby nieprawdą (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, faktyczne_porcje FROM cooked_events WHERE faktyczne_porcje IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE cooked_events DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE cooked_events DROP COLUMN IF EXISTS faktyczne_porcje');
    }
};
