<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2550 (V2): prywatne, odwracalne „Odłóż na później” przy własnym szkicu przepisu.
 *
 * `odlozony_at` NULL = szkic bieżący (stan domyślny, także dla wszystkich
 * istniejących wierszy). Wartość to chwila odłożenia i zarazem znacznik wersji
 * stanu: formularz z innej karty niesie ją ze sobą, więc stare „Wróć do pracy”
 * nie odwraca nowszej decyzji.
 *
 * To NIE jest status moderacji ani widoczności: `status` zostaje `draft`,
 * treść, zdjęcia, kroki i wersje nie są ruszane. Oznaczenie jest tylko
 * organizacją listy autora.
 *
 * CHECK `recipes_odlozony_tylko_niepublikowany_check`: odłożyć można wyłącznie
 * przepis, który nigdy nie został opublikowany (`published_at IS NULL`).
 * `PublishRecipe` zdejmuje oznaczenie w tym samym UPDATE, w którym publikuje.
 * CHECK idzie przez `NOT VALID` + `VALIDATE` (AGENTS.md §6); nowa kolumna
 * `NULL` bez DEFAULT zmienia sam katalog.
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jeden szkic jest odłożony. Usunięcie
 * kolumny po cichu wymieszałoby świadomie odłożone próby z bieżącymi.
 * Ręcznie: `UPDATE recipes SET odlozony_at = NULL`, potem ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->timestampTz('odlozony_at', 6)->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_odlozony_tylko_niepublikowany_check CHECK (odlozony_at IS NULL OR published_at IS NULL) NOT VALID');
            DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT recipes_odlozony_tylko_niepublikowany_check');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipes', 'odlozony_at')) {
            return;
        }

        $odlozone = DB::table('recipes')->whereNotNull('odlozony_at')->count();

        if ($odlozone > 0) {
            throw new RuntimeException(
                "Cofnięcie tej migracji skasowałoby oznaczenia „Odłożone na później” przy {$odlozone} szkicach ludzi. "
                .'Jeśli naprawdę trzeba: zrób kopię (pg_dump -t recipes), '
                .'wyczyść kolumnę ręcznie (UPDATE recipes SET odlozony_at = NULL) i uruchom rollback jeszcze raz.',
            );
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_odlozony_tylko_niepublikowany_check');
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('odlozony_at');
        });
    }
};
