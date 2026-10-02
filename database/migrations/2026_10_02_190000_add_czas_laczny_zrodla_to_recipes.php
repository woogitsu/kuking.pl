<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Czas ŁĄCZNY podany przez źródło importu (`totalTime`) — osobno od
 * `prep_minutes` i `cook_minutes` (#2572, decyzja właściciela z 2.10.2026).
 *
 * Import z adresu zachowuje jawne `totalTime` strony, bez zgadywania podziału
 * na przygotowanie i gotowanie. Kolumna NIE jest czasem Kuking: nie wchodzi do
 * `Recipe::totalMinutes()`, filtra „Do 30 minut" ani `totalTime` w JSON-LD.
 *
 * `integer NULL` (jak `prep_minutes`), CHECK 1..10080 — ta sama górna granica
 * co `ParserJsonLdPrzepisu::minuty()` i pola formularza (7 dni). `NULL` =
 * źródło nie podało; zero byłoby nieprawdą, więc CHECK go odrzuca.
 * `ADD COLUMN … NULL` bez DEFAULT zmienia sam katalog; CHECK idzie przez
 * `NOT VALID` + `VALIDATE` (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA (D-088), gdy któryś przepis ma zapisaną wartość: to
 * informacja ze źródła, której `up()` nie odtworzy (strona mogła się zmienić),
 * a autor mógł się na niej oprzeć przy planowaniu. Ręcznie: wyzeruj kolumnę
 * świadomie (`UPDATE recipes SET czas_laczny_zrodla_minut = NULL`) i ponów.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->integer('czas_laczny_zrodla_minut')->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_czas_laczny_zrodla_check CHECK (czas_laczny_zrodla_minut IS NULL OR (czas_laczny_zrodla_minut > 0 AND czas_laczny_zrodla_minut <= 10080)) NOT VALID');
            DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT recipes_czas_laczny_zrodla_check');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipes', 'czas_laczny_zrodla_minut')) {
            return;
        }

        $zapisanych = DB::table('recipes')->whereNotNull('czas_laczny_zrodla_minut')->count();

        if ($zapisanych > 0) {
            throw new RuntimeException("Cofnięcie usunie czas łączny podany przez źródło w {$zapisanych} przepisach. Jeśli to zamierzone, najpierw wyzeruj kolumnę świadomie: UPDATE recipes SET czas_laczny_zrodla_minut = NULL, a potem ponów rollback.");
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_czas_laczny_zrodla_check');
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('czas_laczny_zrodla_minut');
        });
    }
};
