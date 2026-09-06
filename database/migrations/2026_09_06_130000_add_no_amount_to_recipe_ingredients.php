<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `recipe_ingredients.no_amount` — „sól do smaku" nie skaluje się razy trzy
 * (issue #44).
 *
 * PO CO TO TERAZ, SKORO SKALOWANIE PORCJI JEST DOPIERO W V2
 * Bo teraz kosztuje jedną kolumnę, a później kosztuje migrację danych
 * i ZGADYWANIE. Kiedy w tabeli będą już przepisy prawdziwych ludzi, nikt nie
 * odróżni „mleko — ile weźmie" od „mleko 200 ml" inaczej niż heurystyką po
 * tekście. Heurystyka pomyli się na czyimś przepisie po babci i nie będzie
 * komu tego zauważyć.
 *
 * CO TA FLAGA ZNACZY
 * „Ten składnik NIE MA wymiernej ilości": sól do smaku, pieprz, mleko — ile
 * weźmie. Przepis przeliczony razy trzy poprosiłby inaczej o trzy szczypty
 * soli (śmieszne) i o trzy razy „ile weźmie" (bez sensu). Dla naszego
 * odbiorcy to nie jest drobiazg kosmetyczny — to jest moment, w którym
 * przepis przestaje wyglądać na napisany przez człowieka.
 *
 * CHECK, A NIE SAMA UMOWA W KODZIE
 * `no_amount = true` wyklucza wypełnione `quantity` i `unit_id`. Bez tego
 * dałoby się zapisać wiersz, który jednocześnie mówi „nie mam ilości"
 * i „mam 200 ml" — a wtedy pytanie „czy to skalować" nie ma poprawnej
 * odpowiedzi. Baza jest ostatnim miejscem, które może tego pilnować, kiedy
 * dane wchodzą inną drogą niż formularz (import, seeder, konsola).
 *
 * ROLLBACK
 * `down()` zdejmuje CHECK i kolumnę. Bezstratny w jedną stronę tylko dlatego,
 * że dziś nic tej flagi nie czyta poza widokiem — po wdrożeniu skalowania
 * porcji cofnięcie tej migracji będzie znaczyło utratę informacji, której nie
 * da się odtworzyć. Wtedy trzeba będzie zrobić kopię tabeli przed cofnięciem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            // DEFAULT false, nie NULL. „Nie wiem, czy ma ilość" to stan,
            // z którym skalowanie i tak musiałoby coś zrobić — a skoro musi,
            // niech to będzie stan jawny i domyślnie bezpieczny: składnik
            // zachowuje się tak jak dotąd.
            $table->boolean('no_amount')->default(false);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE recipe_ingredients
                ADD CONSTRAINT recipe_ingredients_no_amount_check
                CHECK (no_amount = false OR (quantity IS NULL AND unit_id IS NULL))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS recipe_ingredients_no_amount_check');
        }

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropColumn('no_amount');
        });
    }
};
