<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZAMIENNIKI SKŁADNIKA — TEKST OD AUTORA (D-284, V2).
 *
 * `recipe_ingredients.substitutes varchar(300) NULL` — czym autor radzi
 * zastąpić ten jeden składnik: „margaryna albo olej kokosowy”. Wolny tekst
 * od człowieka, pokazywany pod składnikiem na stronie przepisu jako
 * „Zamiast tego: …”. `NULL` jest stanem normalnym — większość składników
 * zamiennika nie ma.
 *
 * DLACZEGO OSOBNA KOLUMNA, A NIE `note`
 * `note` to dopisek o SAMYM składniku („najlepiej wiejskie”). Zamiennik to
 * inna informacja o innym produkcie: widz szuka go wtedy, gdy czegoś nie ma
 * w domu, a przyszła lista zakupów i zamienniki podpowiadane przez AI
 * (propozycja w D-284) potrzebują go osobno, nie wyłuskanego z dopisku.
 *
 * CHECK `recipe_ingredients_substitutes_check`: pusty ciąg albo same spacje
 * to w bazie `NULL`, nie „zamiennik bez treści” — inaczej widok pisałby
 * „Zamiast tego:” i nic. `PublishRecipe` zamienia puste na `NULL` przed
 * zapisem; CHECK jest ostatnią linią, nie pierwszą.
 *
 * ISTNIEJĄCA TABELA (AGENTS.md §6): kolumna `NULL` bez wartości domyślnej to
 * zmiana w katalogu, bez przepisywania tabeli; CHECK wchodzi jako `NOT VALID`
 * i osobno `VALIDATE` — poza jedną transakcją, żeby krótka blokada z pierwszego
 * kroku nie trwała przez czytanie tabeli w drugim.
 *
 * ROLLBACK ODMAWIA, GDY JEST CO STRACIĆ (D-088, wzorzec `hero_picks`)
 * Zdjęcie kolumny kasuje tekst, który napisali ludzie, a po `down()` prawie
 * zawsze idzie kolejny `migrate` — kolumna wraca pusta i nie ma błędu do
 * zauważenia. Dlatego `down()` przy choć jednym zapisanym zamienniku
 * przerywa się z instrukcją. Na pustej kolumnie i na świeżej bazie
 * przechodzi bez pytania. Jawna zgoda: `KUKING_ROLLBACK_KASUJ_ZAMIENNIKI=true`.
 * Kopie w `recipe_versions.snapshot` zostają nietknięte w obu przypadkach.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'recipe_ingredients_substitutes_check';

    public function up(): void
    {
        if (! Schema::hasColumn('recipe_ingredients', 'substitutes')) {
            Schema::table('recipe_ingredients', function (Blueprint $table): void {
                $table->string('substitutes', 300)->nullable();
            });
        }

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT '.self::OGRANICZENIE
            ." CHECK (substitutes IS NULL OR btrim(substitutes) <> '') NOT VALID");
        DB::statement('ALTER TABLE recipe_ingredients VALIDATE CONSTRAINT '.self::OGRANICZENIE);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipe_ingredients', 'substitutes')) {
            return;
        }

        // Sprawdzenie i DDL pod jedną blokadą: zapis przepisu, który wszedłby
        // między policzenie a `DROP COLUMN`, zniknąłby bez śladu.
        DB::transaction(function (): void {
            if ($this->isPostgres()) {
                DB::statement('LOCK TABLE recipe_ingredients IN ACCESS EXCLUSIVE MODE');
            }

            $zapisanych = (int) DB::table('recipe_ingredients')->whereNotNull('substitutes')->count();

            if ($zapisanych > 0 && ! $this->wolnoSkasowac()) {
                // Rzeczownik przed liczbą, liczba na końcu zdania — poprawne
                // po polsku dla 1, 2, 5 i 22 (D-132).
                throw new RuntimeException(
                    'Cofnięcie tej migracji skasowałoby zamienniki składników wpisane przez autorów przepisów. '
                    .'Liczba składników z zamiennikiem: '.$zapisanych.".\n\n"
                    ."CZYM TO GROZI\n"
                    .'Po ponownym `migrate` kolumna wróci pusta, a przepisy stracą podpowiedzi '
                    ."„Zamiast tego: …” bez jednego komunikatu.\n\n"
                    ."CO ZROBIĆ ZAMIAST TEGO\n"
                    ."Wycofaj sam kod, zostawiając kolumnę — stary kod jej nie czyta. Albo zapisz dane:\n"
                    ."  \\copy (SELECT id, recipe_id, substitutes FROM recipe_ingredients WHERE substitutes IS NOT NULL) \n"
                    ."  TO 'zamienniki.csv' CSV HEADER\n\n"
                    ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                    .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_ZAMIENNIKI=true php artisan migrate:rollback',
                );
            }

            if ($this->isPostgres()) {
                DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
            }

            Schema::table('recipe_ingredients', function (Blueprint $table): void {
                $table->dropColumn('substitutes');
            });
        });
    }

    /** `getenv()`, nie `env()` — przy zbuforowanej konfiguracji `env()` oddaje `null`. */
    private function wolnoSkasowac(): bool
    {
        return filter_var((string) getenv('KUKING_ROLLBACK_KASUJ_ZAMIENNIKI'), FILTER_VALIDATE_BOOLEAN);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
