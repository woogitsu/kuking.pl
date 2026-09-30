<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UKRYWANIE POJEDYNCZEJ WERSJI PRZEPISU (issue #2270, decyzja właściciela
 * z 30.09.2026, D-333).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Historia wersji (#2024) jest publiczna tak samo jak przepis. Wersja
 * zachowuje treść z chwili zapisu, także tę, którą autor później usunął
 * (np. numer telefonu babci w opisie). Do dziś jedyną drogą było usunięcie
 * CAŁEGO przepisu. Autor i moderacja mogą teraz ukryć jedną wersję: nie
 * widać jej publicznie, a autor i moderacja widzą ją z oznaczeniem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KOLUMNY — tylko to, czego potrzebuje reguła
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - `hidden_at timestamptz NULL` — OD KIEDY wersja jest ukryta; `NULL`
 *    = widoczna jak dotąd.
 *  - `hidden_by_role varchar(10) NULL` — KTO ukrył, jako strona sprawy:
 *    `author` albo `moderator`. Ta jedna informacja rozstrzyga, kto może
 *    ukrycie cofnąć (autor nie cofa ukrycia moderacji, moderacja nie
 *    odsłania tego, co autor ukrył sam). KTÓRE konto to zrobiło, stoi
 *    w `audit_log` (`recipe_version.hidden`) — tu świadomie nie ma
 *    `uuid` konta: kolumna wskazująca na konto weszłaby do inwentarza
 *    danych konta, eksportu i wymazywania, a reguła jej nie potrzebuje.
 *
 * CHECK `recipe_versions_hidden_spojny_check`: obie kolumny puste albo obie
 * wypełnione, z rolą ze słownika.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  §6: ADD COLUMN bez DEFAULT + CHECK NOT VALID + VALIDATE, poza transakcją
 * ────────────────────────────────────────────────────────────────────────
 *
 * `ADD COLUMN … NULL` bez DEFAULT zmienia tylko katalog. CHECK idzie jako
 * `NOT VALID`, a `VALIDATE` osobno — wszystkie istniejące wiersze mają obie
 * kolumny `NULL`, więc walidacja przechodzi.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — ODMAWIA przy ukrytej wersji (D-088)
 * ────────────────────────────────────────────────────────────────────────
 *
 * `hidden_at` niesie decyzję człowieka o prywatności. Zdjęcie kolumny
 * ODSŁANIA każdą ukrytą wersję publicznie, a ponowne `up()` wraca z `NULL`,
 * czyli nie ukrywa niczego — bez śladu błędu. Dlatego `down()` odmawia,
 * gdy choć jedna wersja jest ukryta, i mówi, co zrobić ręcznie. Przy
 * samych `NULL` (świeża baza, CI) przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'recipe_versions_hidden_spojny_check';

    // `hidden_by_role IS NOT NULL` stoi jawnie: samo `IN (...)` przy NULL
    // daje NULL, a CHECK traktuje NULL jak „przechodzi" — wersja ukryta bez
    // strony przeszłaby przez bazę (złapane testem przed scaleniem).
    private const WARUNEK = '(hidden_at IS NULL AND hidden_by_role IS NULL) '
        ."OR (hidden_at IS NOT NULL AND hidden_by_role IS NOT NULL AND hidden_by_role IN ('author','moderator'))";

    public function up(): void
    {
        if (! Schema::hasColumn('recipe_versions', 'hidden_at')) {
            DB::statement('ALTER TABLE recipe_versions ADD COLUMN hidden_at timestamptz NULL');
        }
        if (! Schema::hasColumn('recipe_versions', 'hidden_by_role')) {
            DB::statement('ALTER TABLE recipe_versions ADD COLUMN hidden_by_role varchar(10) NULL');
        }

        DB::statement('ALTER TABLE recipe_versions DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT '.self::OGRANICZENIE.' CHECK ('.self::WARUNEK.') NOT VALID');

        try {
            DB::statement('ALTER TABLE recipe_versions VALIDATE CONSTRAINT '.self::OGRANICZENIE);
        } catch (Throwable $e) {
            DB::statement('ALTER TABLE recipe_versions DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);

            throw new RuntimeException(
                'Nie udało się zwalidować '.self::OGRANICZENIE.': w recipe_versions jest wiersz z hidden_at '
                .'bez hidden_by_role (albo odwrotnie). CHECK został zdjęty. Popraw te wiersze '
                .'(zapytanie w docs/DATABASE.md, sekcja recipe_versions) i uruchom migrację ponownie.',
                previous: $e,
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('recipe_versions', 'hidden_at')) {
            $ukryte = DB::table('recipe_versions')->whereNotNull('hidden_at')->count();

            if ($ukryte > 0) {
                throw new RuntimeException(
                    "Cofnięcie odmówione (D-088): {$ukryte} wersji przepisu jest ukrytych przez autora albo moderację. "
                    .'Zdjęcie kolumny hidden_at pokazałoby je znowu publicznie w „Historii zmian”, a ponowne '
                    .'migrate niczego by już nie ukryło. Jeśli naprawdę trzeba cofnąć: zapisz kopię '
                    .'(SELECT id, hidden_at, hidden_by_role FROM recipe_versions WHERE hidden_at IS NOT NULL), '
                    .'wyzeruj obie kolumny w tych wierszach, cofnij migrację, a po ponownym migrate przywróć '
                    .'ukrycie z kopii (UPDATE po id) — zanim nowa wersja kodu zacznie obsługiwać ruch.',
                );
            }
        }

        DB::statement('ALTER TABLE recipe_versions DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE recipe_versions DROP COLUMN IF EXISTS hidden_by_role');
        DB::statement('ALTER TABLE recipe_versions DROP COLUMN IF EXISTS hidden_at');
    }
};
