<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * „Ile gotowych sztuk wychodzi z przepisu” (V2, #2645, D-333).
 *
 * - `recipes.yield_count` — `integer NULL`, liczba gotowych sztuk (24 pierogi,
 *   12 bułek). NULL = autor nie podał. CHECK 1–9999.
 * - `recipes.yield_unit` — `varchar(40) NULL`, CO to za sztuki („pierogi”).
 *   NULL = sama liczba. CHECK: niepusty po przycięciu, najwyżej 40 znaków
 *   i tylko razem z liczbą (jednostka bez liczby nic nie znaczy).
 *
 * Kolumny są OSOBNE od `servings`. Liczba pierogów nie określa liczby osób ani
 * porcji posiłku, więc istniejące wartości nie są przepisywane na nowe
 * znaczenie, a import nie zgaduje sztuk z tytułu ani opisu (#2539).
 *
 * `ADD COLUMN … NULL` na PostgreSQL nie przepisuje tabeli. Ograniczenia
 * idą wzorcem NOT VALID + VALIDATE w osobnych transakcjach (AGENTS.md §6).
 *
 * WYCOFANIE ODMAWIA, GDY KTOŚ PODAŁ LICZBĘ SZTUK (D-088). To treść przepisu
 * wpisana przez autora; po cofnięciu zniknęłaby bez śladu razem z przeliczeniem
 * „24 → 36”. Wymuszenie: `KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK=1`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->integer('yield_count')->nullable();
            $table->string('yield_unit', 40)->nullable();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_yield_count_check CHECK (yield_count IS NULL OR (yield_count >= 1 AND yield_count <= 9999)) NOT VALID');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_yield_unit_check CHECK (yield_unit IS NULL OR (yield_count IS NOT NULL AND char_length(btrim(yield_unit)) >= 1 AND char_length(yield_unit) <= 40)) NOT VALID');
            DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT recipes_yield_count_check');
            DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT recipes_yield_unit_check');
        }
    }

    public function down(): void
    {
        // Strażnik PRZED zdjęciem kolumny — potem nie ma czego policzyć.
        if (Schema::hasColumn('recipes', 'yield_count')) {
            $ile = (int) DB::table('recipes')->whereNotNull('yield_count')->count();

            // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
            if ($ile > 0 && getenv('KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK') !== '1') {
                throw new RuntimeException(<<<TEKST
                    Cofnięcie tej migracji skasuje liczbę gotowych sztuk, którą autorzy wpisali przy swoich przepisach — bezpowrotnie.
                    Liczba przepisów, którym zniknie ta informacja: {$ile}.

                    Zanim cofniesz:
                      1. zrób kopię danych:
                         CREATE TABLE recipes_yield_kopia AS SELECT id, yield_count, yield_unit FROM recipes WHERE yield_count IS NOT NULL;
                      2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu przepisu
                         naprawia się bez ruszania bazy;
                      3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK=1.

                    Na świeżej bazie albo gdy nikt nie podał liczby sztuk, cofnięcie działa bez pytania.
                    TEKST);
            }
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_yield_unit_check');
            DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_yield_count_check');
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn(['yield_count', 'yield_unit']);
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
