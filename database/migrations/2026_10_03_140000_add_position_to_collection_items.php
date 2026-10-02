<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ręczna kolejność przepisów w zeszycie (#2544, V2, F7/D-333).
 *
 * `collection_items.position integer NULL` — miejsce przepisu na liście, którą
 * właściciel ułożył sam. Zasady, od których zależy reszta kodu:
 *
 *  - `NULL` = „nikt tego nie układał": zeszyt wygląda tak jak dotąd, od
 *    najnowszego zapisu (`created_at DESC`, potem id przepisu). Migracja NIE
 *    nadaje pozycji istniejącym wierszom — zwykły sposób przeglądania zostaje
 *    nietknięty, a ręczny porządek powstaje dopiero z pierwszego świadomego
 *    kliknięcia „Wyżej" / „Niżej" (`PrzesunPrzepisWZeszycie`);
 *  - pozycje są liczbami dodatnimi i dotyczą WYŁĄCZNIE przepisów (wiersze
 *    z `post_id` zostają bez pozycji) — CHECK `collection_items_position_check`;
 *  - w jednym zeszycie dwa przepisy nie mają tej samej pozycji — unikalny
 *    indeks częściowy `collection_items_position_unique`. Dziury (po wyjęciu
 *    przepisu) są dozwolone i niczemu nie szkodzą: przesuwanie liczy się po
 *    kolejności, nie po różnicy numerów;
 *  - `created_at` nie jest pozycją: daty zapisów służą historii i sekcji
 *    „Ostatnio zapisane" i przesuwanie ich nie rusza.
 *
 * `collection_items` jest ISTNIEJĄCĄ, gorącą tabelą (AGENTS.md §6): kolumna bez
 * wartości domyślnej to zmiana samego katalogu, CHECK dodajemy `NOT VALID` i
 * osobno `VALIDATE`, indeks budujemy `CONCURRENTLY` (stąd brak transakcji;
 * przerwana budowa zostawia INVALID, więc przed budową go zdejmujemy).
 *
 * ROLLBACK ODMAWIA, GDY ZGUBIŁBY UKŁAD CZŁOWIEKA (D-088)
 * Ręczna kolejność to decyzja właściciela, której `up()` nie odtworzy.
 * Cofnięcie po cichu zamieniłoby „zupa → danie → deser" w kolejność zapisu.
 * Dlatego `down()` przerywa, gdy choć jeden przepis ma pozycję, i mówi, co
 * zrobić: zrobić kopię (`CREATE TABLE … AS SELECT …`) albo najpierw przywrócić
 * kolejność zapisu przyciskiem „Wróć do kolejności zapisu" w zeszycie. Na bazie
 * bez ręcznych układów cofa się bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK = 'collection_items_position_check';

    private const INDEKS = 'collection_items_position_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE collection_items ADD COLUMN IF NOT EXISTS position integer NULL');

        DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE collection_items ADD CONSTRAINT '.self::CHECK
            .' CHECK (position IS NULL OR (position > 0 AND recipe_id IS NOT NULL)) NOT VALID');
        DB::statement('ALTER TABLE collection_items VALIDATE CONSTRAINT '.self::CHECK);

        $wspolbieznie = $this->wspolbieznie();

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE UNIQUE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON collection_items (collection_id, position) WHERE position IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->odmowJesliZgubiUklad();

        DB::statement('DROP INDEX '.$this->wspolbieznie().'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE collection_items DROP COLUMN IF EXISTS position');
    }

    private function odmowJesliZgubiUklad(): void
    {
        $kolumna = DB::selectOne(
            'SELECT 1 AS jest FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['collection_items', 'position'],
        );

        if ($kolumna === null) {
            return;
        }

        $wiersz = DB::selectOne(
            'SELECT count(*) AS pozycji, count(DISTINCT collection_id) AS zeszytow FROM collection_items WHERE position IS NOT NULL',
        );

        if ((int) $wiersz->pozycji === 0) {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji zgubi ręczną kolejność przepisów w zeszytach.
            Zeszytów z ułożoną kolejnością: {$wiersz->zeszytow}, przepisów z pozycją: {$wiersz->pozycji}.
            Ponowna migracja nie odtworzy ułożenia — wszystkie zeszyty wróciłyby do kolejności zapisu.

            Zanim cofniesz:
              1. zrób kopię: CREATE TABLE collection_items_pozycje_kopia AS SELECT collection_id, recipe_id, position FROM collection_items WHERE position IS NOT NULL;
              2. albo poproś właścicieli o „Wróć do kolejności zapisu" w ich zeszytach (to zeruje pozycje) — błąd w ekranie naprawia się bez ruszania bazy;
              3. po ponownej migracji przywróć pozycje z kopii.
            TEKST);
    }

    private function wspolbieznie(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
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
