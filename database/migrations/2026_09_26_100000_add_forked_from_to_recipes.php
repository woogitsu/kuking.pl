<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Moja wersja" — przepis zrobiony na podstawie cudzego przepisu (issue #23, D-301).
 *
 * DWIE KOLUMNY, BO TO SĄ DWIE RÓŻNE PRAWDY
 *
 *  - `forked_from_id` — KTÓRY przepis był oryginałem. Klucz obcy do `recipes`
 *    z `ON DELETE SET NULL`: twarde skasowanie oryginału (wymazanie konta
 *    autora, `EraseAccountData`) nie może skasować cudzej wersji ani zatrzymać
 *    kasowania konta na kluczu obcym. Zwykłe usunięcie przepisu jest miękkie
 *    (`deleted_at`), więc tam wskazanie zostaje, a widok pisze „oryginał jest
 *    niedostępny".
 *  - `forked_at` — ŻE ten przepis w ogóle jest wersją cudzego. Zostaje także
 *    wtedy, gdy `forked_from_id` wyzeruje się przy twardym skasowaniu
 *    oryginału. Bez niego wersja po wymazaniu konta autora oryginału
 *    wyglądałaby w bazie jak zwykły przepis własny — czyli dokładnie to
 *    „przepisywanie cudzego bez podpisu", przed którym ostrzega issue.
 *
 * CHECK pilnuje, że wskazanie nie istnieje bez znacznika i że przepis nie jest
 * wersją samego siebie.
 *
 * DDL NA ISTNIEJĄCEJ TABELI (AGENTS.md §6)
 *
 * `ADD COLUMN` bez `DEFAULT` jest zmianą samego katalogu — nie przepisuje
 * tabeli. Klucz obcy i CHECK idą przez `NOT VALID` + osobne `VALIDATE`,
 * indeks przez `CONCURRENTLY`, wszystko poza jedną transakcją
 * (`$withinTransaction = false`), żeby blokada z pierwszego kroku nie trwała
 * do końca ostatniego. Wzorce: `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem`
 * i `2026_09_25_100000_indeksy_kluczy_obcych_na_goracych_sciezkach`.
 *
 * ROLLBACK ODMAWIA, GDY W BAZIE SĄ WERSJE (D-088)
 *
 * Podpis „Na podstawie przepisu…" jest przypisaniem autorstwa, a nie
 * preferencją wyglądu. Gdyby `down()` zdjęło kolumny, kolejny `migrate`
 * odtworzyłby je puste — każda istniejąca wersja stałaby się po cichu
 * „przepisem własnym" swojego autora. Dlatego przy choćby jednej wersji
 * cofnięcie odmawia z instrukcją, a na świeżej bazie przechodzi bez pytania.
 * Pilnuje `tests/Feature/CofniecieMigracjiNieGubiPodpisuWersjiTest.php`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const KLUCZ_OBCY = 'recipes_forked_from_id_foreign';

    private const CHECK = 'recipes_forked_spojny_check';

    private const WARUNEK = '(forked_from_id IS NULL OR forked_at IS NOT NULL) '
        .'AND (forked_from_id IS NULL OR forked_from_id <> id)';

    private const INDEKS = 'recipes_forked_from_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Przez `Schema`, nie surowym SQL-em: z tych wywołań Larastan czyta
        // kolumny modelu. `hasColumn` zamiast `IF NOT EXISTS` — ponowne
        // uruchomienie po przerwanej migracji nie pada na istniejącej kolumnie.
        if (! Schema::hasColumn('recipes', 'forked_from_id')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->uuid('forked_from_id')->nullable();
            });
        }

        if (! Schema::hasColumn('recipes', 'forked_at')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->timestampTz('forked_at', 0)->nullable();
            });
        }

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::KLUCZ_OBCY);
        DB::statement(
            'ALTER TABLE recipes ADD CONSTRAINT '.self::KLUCZ_OBCY
            .' FOREIGN KEY (forked_from_id) REFERENCES recipes (id) ON DELETE SET NULL NOT VALID',
        );
        DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT '.self::KLUCZ_OBCY);

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT '.self::CHECK.' CHECK ('.self::WARUNEK.') NOT VALID');
        DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT '.self::CHECK);

        // Indeks pod listę „Wersje innych osób" na stronie oryginału i pod
        // `ON DELETE SET NULL` (bez niego twarde skasowanie przepisu
        // przeszukiwałoby całą tabelę `recipes`). Częściowy: wersji jest
        // i będzie mało w stosunku do wszystkich przepisów.
        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement(
            'CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON recipes (forked_from_id) WHERE forked_from_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // STRAŻNIK STOI PRZED PIERWSZYM `DROP` — po zdjęciu kolumny nie ma
        // już czego policzyć.
        $wersji = (int) DB::table('recipes')->whereNotNull('forked_at')->count();

        if ($wersji > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba przepisów, które są czyjąś wersją cudzego przepisu '
                .'(forked_at IS NOT NULL): '.$wersji.'. Kolumny forked_from_id i forked_at niosą '
                .'podpis „Na podstawie przepisu…", czyli przypisanie autorstwa oryginału. Gdyby '
                .'cofnięcie przeszło, kolejny `migrate` odtworzyłby je puste i każda z tych wersji '
                .'stałaby się po cichu przepisem własnym swojego autora (issue #23, D-301, D-088).'
                ."\n\nCO ZROBIĆ:\n"
                .'  - jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '
                .'cofaj TEJ migracji — kod sprzed niej nie czyta tych kolumn i działa z nimi bez zmian;'
                ."\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz powiązania PRZED cofnięciem:\n"
                ."      SELECT id, forked_from_id, forked_at FROM recipes WHERE forked_at IS NOT NULL;\n"
                .'    a po powrocie na tę wersję schematu odtwórz je tym samym `UPDATE`, zanim '
                .'ktokolwiek zobaczy te przepisy.',
            );
        }

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::KLUCZ_OBCY);
        DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS forked_at');
        DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS forked_from_id');
    }

    private function jestNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};
