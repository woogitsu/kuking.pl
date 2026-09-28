<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Szacunkowy koszt całego przepisu, podany przez autora (V2, D-286).
 *
 * `numeric(6,2) NULL` — złote z groszami, najwyżej 9999,99 zł. `NULL` jest
 * stanem normalnym: autor nie podał kosztu i strona przepisu o koszcie
 * milczy. Bez backfillu i bez `DEFAULT` — zero złotych to TEŻ odpowiedź
 * (danie z tego, co rośnie w ogródku), więc nie może udawać „nie podano".
 *
 * `ADD COLUMN` bez domyślnej wartości nie przepisuje tabeli (sama zmiana
 * katalogu). CHECK idzie jako `NOT VALID` + osobne `VALIDATE` poza jedną
 * transakcją (AGENTS.md §6, wzorzec
 * `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php`).
 *
 * ROLLBACK ODMAWIA, GDY KTOŚ JUŻ WPISAŁ KOSZT (D-088, D-286). Zdjęcie kolumny
 * kasuje liczbę wpisaną przez człowieka, a kolejny `migrate` odtworzyłby ją
 * jako `NULL` — bez śladu błędu. Na świeżej bazie i przy samych `NULL`-ach
 * rollback przechodzi bez pytania (test odmowy i kontrola dodatnia:
 * `tests/Feature/KosztPrzepisuMigracjaTest.php`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Przez `Schema`, nie surowym SQL-em: z tej deklaracji Larastan
        // wie, że kolumna istnieje (`Recipe::$estimated_cost_pln`).
        if (! Schema::hasColumn('recipes', 'estimated_cost_pln')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->decimal('estimated_cost_pln', 6, 2)->nullable();
            });
        }

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_estimated_cost_pln_check');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_estimated_cost_pln_check
            CHECK (estimated_cost_pln IS NULL OR estimated_cost_pln >= 0) NOT VALID');
        DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT recipes_estimated_cost_pln_check');
    }

    public function down(): void
    {
        $zKosztem = (int) DB::scalar('SELECT count(*) FROM recipes WHERE estimated_cost_pln IS NOT NULL');

        if ($zKosztem > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba przepisów z kosztem wpisanym przez autora '.
                '(estimated_cost_pln IS NOT NULL): '.$zKosztem.'. To jest liczba napisana przez '.
                'człowieka — zdjęcie kolumny skasowałoby ją bez śladu, a kolejny `migrate` odtworzyłby '.
                'kolumnę pustą, jakby nikt nic nie wpisał. CO ZROBIĆ: najpierw zachowaj wartości, '.
                'np. `CREATE TABLE kopia_kosztow_przepisow AS SELECT id, estimated_cost_pln FROM recipes '.
                'WHERE estimated_cost_pln IS NOT NULL;`, potem świadomie wyczyść kolumnę '.
                '(`UPDATE recipes SET estimated_cost_pln = NULL;`) i dopiero wtedy powtórz cofnięcie. '.
                'Po ponownym wdrożeniu przywróć wartości z kopii.',
            );
        }

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_estimated_cost_pln_check');
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('estimated_cost_pln');
        });
    }
};
