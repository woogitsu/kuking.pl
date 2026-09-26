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
 * ROLLBACK (D-088)
 * `down()` zdejmuje CHECK i kolumnę — ale dopiero, gdy w tabeli nie ma ani
 * jednego składnika oznaczonego `no_amount = true`. Do 26 września 2026
 * kolumna była „bezstratna" tylko dlatego, że nic jej jeszcze nie czytało
 * poza widokiem: po wdrożeniu skalowania porcji (V2, D-284) ta flaga
 * rozstrzyga, których składników NIE mnożyć przy przeliczeniu porcji, i nie
 * da się jej odtworzyć z samego tekstu składnika („sól do smaku" i „sól 5 g"
 * wyglądają w kolumnie `ingredient_text` identycznie, gdy `no_amount` już
 * zniknęło). Cichy `dropColumn` na żywej bazie z prawdziwymi przepisami
 * byłby dokładnie tym samym błędem co trzy przypadki z `AGENTS.md` §6:
 * strata, o której `migrate:rollback` nie mówi ani słowem.
 *
 * Strażnik jest WĄSKI — patrzy tylko na `no_amount = true`. Na świeżej
 * bazie i wszędzie, gdzie nikt jeszcze nie oznaczył żadnego składnika jako
 * „bez ilości", `down()` przechodzi bez pytania; blokowanie rollbacku na
 * zawsze byłoby błędem tej samej wagi w drugą stronę.
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
            // Blokada PRZED liczeniem, nie po: bez niej między policzeniem
            // wierszy a `DROP COLUMN` mógłby wejść nowy składnik z
            // `no_amount = true`, którego strażnik już by nie zobaczył.
            DB::statement('LOCK TABLE recipe_ingredients IN ACCESS EXCLUSIVE MODE');
        }

        $bezIlosci = DB::table('recipe_ingredients')->where('no_amount', true)->count();

        if ($bezIlosci > 0 && getenv('KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI') !== '1') {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania (D-132/D-133) —
            // ten sam wzorzec co w pozostałych strażnikach `down()`.
            throw new RuntimeException(
                'Liczba składników oznaczonych jako „bez wymiernej ilości" (`no_amount = true`) '
                .'w tabeli `recipe_ingredients`: '.$bezIlosci.'. '
                .'Cofnięcie tej migracji usunie kolumnę `no_amount` razem z tym oznaczeniem — po '
                .'wdrożeniu skalowania porcji (V2, D-284) ta flaga rozstrzyga, których składników '
                .'NIE mnożyć, i nie da się jej odtworzyć z samego tekstu składnika.'
                ."\n\n"
                .'Zrób kopię tabeli, a potem uruchom ponownie '
                .'z KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI=1.',
            );
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS recipe_ingredients_no_amount_check');
        }

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropColumn('no_amount');
        });
    }
};
