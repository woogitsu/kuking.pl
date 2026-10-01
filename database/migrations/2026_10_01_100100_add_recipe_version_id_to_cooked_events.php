<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Własne wykonanie pamięta wersję przepisu, którą kucharz miał otwartą
 * (issue #2378).
 *
 * `cooked_events.recipe_version_id uuid NULL` → `recipe_versions (id)`
 * `ON DELETE SET NULL`. Wskaźnik, nie kopia: do wykonania nie trafia żadna
 * treść przepisu. Treść czyta się z `recipe_versions.snapshot` wyłącznie
 * przez Policy właściciela wykonania (`CookedEventPolicy::viewVersion`).
 *
 * DLACZEGO `SET NULL`, A NIE `CASCADE` ANI `RESTRICT`
 *  - `CASCADE` skasowałby wspomnienie człowieka (notatkę, zdjęcie, czas)
 *    razem z wersją, którą wyczyściła retencja (#2024, D-333: 24 miesiące
 *    i spoza 3 najnowszych). „Poprawne dane nigdy nie znikają".
 *  - `RESTRICT` zablokowałby `kuking:sprzataj-wersje-przepisow` i sprawił,
 *    że wykonanie wydłuża życie treści, którą autor chciał usunąć.
 *  Po `SET NULL` wykonanie zostaje, a ekran mówi po polsku, że wersji
 *  już nie ma. Wersje usuniętego przepisu idą razem z nim; wykonania też
 *  (`cooked_events.recipe_id` jest `CASCADE`). Wymazanie konta kucharza
 *  kasuje jego wykonania i wskaźnik razem z nimi.
 *
 * KOLUMNA `NULL`-OWALNA, BEZ BACKFILLU. Wykonania sprzed tej migracji nie
 * wiedzą, z której wersji gotowano; zgadywanie „najbliższej z czasu"
 * byłoby zmyślonym stanem historycznym. `NULL` znaczy „nie wiadomo".
 *
 * INDEKS częściowy jest potrzebny kaskadzie `SET NULL`: bez niego każde
 * skasowanie wersji przez retencję skanowałoby całe `cooked_events`.
 *
 * DDL poza transakcją (AGENTS.md §6): klucz obcy `NOT VALID`, potem
 * `VALIDATE`; indeks `CONCURRENTLY`.
 *
 * ROLLBACK ODMAWIA, GDY KTÓREŚ WYKONANIE MA WSKAŹNIK (D-088). Kolejny
 * `migrate` odtworzyłby kolumnę pustą, czyli po cichu zgubił fakt, z której
 * wersji gotowano. Na świeżej bazie i przy samych `NULL`-ach cofnięcie
 * przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEKS = 'cooked_events_recipe_version_idx';

    private const FK = 'cooked_events_recipe_version_id_foreign';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS recipe_version_id uuid NULL');

        if (! $this->maKlucz()) {
            DB::statement('ALTER TABLE cooked_events ADD CONSTRAINT '.self::FK
                .' FOREIGN KEY (recipe_version_id) REFERENCES recipe_versions (id) ON DELETE SET NULL NOT VALID');
        }
        DB::statement('ALTER TABLE cooked_events VALIDATE CONSTRAINT '.self::FK);

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        if ($this->indeksNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON cooked_events (recipe_version_id) WHERE recipe_version_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $przypiete = DB::table('cooked_events')->whereNotNull('recipe_version_id')->count();

        if ($przypiete > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań przypiętych do wersji przepisu '
                .'(cooked_events.recipe_version_id IS NOT NULL): '.$przypiete.'. '
                .'Kolejny `migrate` odtworzyłby kolumnę pustą i po cichu zgubił, z której wersji ktoś gotował (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, recipe_version_id FROM cooked_events WHERE recipe_version_id IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('DROP INDEX '.(DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '').'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE cooked_events DROP CONSTRAINT IF EXISTS '.self::FK);
        DB::statement('ALTER TABLE cooked_events DROP COLUMN IF EXISTS recipe_version_id');
    }

    private function maKlucz(): bool
    {
        return DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::FK]) !== null;
    }

    private function indeksNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};
