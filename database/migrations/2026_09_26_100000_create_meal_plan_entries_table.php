<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planer tygodnia (#27, D-310) — pierwszy krok z „najmniejszej kolejności”
 * w issue: dzień + przepis ALBO własny wpis („obiad u mamy”), bez listy
 * zakupów.
 *
 * Jeden wiersz to jedna pozycja jednego dnia. Plan jest prywatny — widzi go
 * tylko właściciel — więc nie ma tu kolumny widoczności.
 *
 * `recipe_id` z `ON DELETE SET NULL`, a nie `CASCADE`: usunięcie przepisu nie
 * kasuje ręcznie ułożonego planu (kryterium z issue). Przepisy kasujemy
 * miękko, więc twarde usunięcie zdarza się rzadko (wymazanie konta autora);
 * wtedy pozycja zostaje bez przepisu i bez tekstu, a ekran mówi „Przepis
 * został usunięty.” — dlatego „co najmniej jedno z dwóch” NIE jest CHECK-iem.
 * „Najwyżej jedno z dwóch” już jest.
 *
 * Dwa indeksy unikalne pilnują, żeby ten sam przepis i ten sam własny wpis
 * nie stały dwa razy w jednym dniu — także przy podwójnym kliknięciu
 * i przy ponownym kopiowaniu tygodnia (`insertOrIgnore`).
 *
 * ROLLBACK: `down()` kasuje tabelę razem z planami ludzi. To nie jest
 * wartość semantyczna w rozumieniu D-088 (zgoda, zakres usunięcia,
 * widoczność), tylko prywatne notatki, ale przepadają bez śladu — dlatego
 * `down()` ODMAWIA, gdy w tabeli są wiersze. Na świeżej i pustej bazie
 * (CI, `migrate:refresh`) przechodzi bez pytania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->string('label', 120)->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE meal_plan_entries ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_jedno_z_dwoch_check
            CHECK (recipe_id IS NULL OR label IS NULL)');
        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_label_check
            CHECK (label IS NULL OR char_length(btrim(label)) BETWEEN 1 AND 120)');
        DB::statement('CREATE INDEX meal_plan_entries_user_day_idx ON meal_plan_entries (user_id, day)');
        DB::statement('CREATE UNIQUE INDEX meal_plan_entries_przepis_raz_na_dzien
            ON meal_plan_entries (user_id, day, recipe_id) WHERE recipe_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX meal_plan_entries_wpis_raz_na_dzien
            ON meal_plan_entries (user_id, day, label) WHERE label IS NOT NULL');
        // Klucz obcy bez indeksu to pełny skan przy kasowaniu przepisu.
        DB::statement('CREATE INDEX meal_plan_entries_recipe_idx ON meal_plan_entries (recipe_id) WHERE recipe_id IS NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasTable('meal_plan_entries') && DB::table('meal_plan_entries')->exists()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby plany tygodnia ludzi (meal_plan_entries nie jest pusta). '
                .'Jeśli naprawdę trzeba: zrób kopię tabeli (pg_dump -t meal_plan_entries), '
                .'usuń wiersze ręcznie i uruchom rollback jeszcze raz.',
            );
        }

        Schema::dropIfExists('meal_plan_entries');
    }
};
