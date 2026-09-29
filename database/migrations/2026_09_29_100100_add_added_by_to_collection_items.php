<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kto dodał pozycję do zeszytu (#1743, D-302).
 *
 * We wspólnym zeszycie „kto to tu wrzucił" jest częścią treści: widać to przy
 * każdej pozycji. `collection_items.added_by_id`:
 *
 *  - dla wierszy sprzed tej migracji — właściciel zeszytu. Do dziś nikt poza
 *    nim nie mógł niczego dopisać, więc to jest fakt, nie zgadywanie;
 *  - dla nowych — osoba, która kliknęła „Zapisuję" (`SaveRecipeToCollection`,
 *    `SavePostToCollection`);
 *  - NULL — dopisała to osoba, której konto zostało już usunięte (D-302).
 *    Pozycja zostaje w zeszycie właściciela, znika tylko powiązanie z kontem.
 *
 * `collection_items` jest ISTNIEJĄCĄ, gorącą tabelą (AGENTS.md §6):
 *  - kolumna bez wartości domyślnej — zmiana samego katalogu, bez przepisania;
 *  - klucz obcy `NOT VALID`, potem osobno `VALIDATE` — dlatego migracja chodzi
 *    poza transakcją (`$withinTransaction = false`);
 *  - indeks `CONCURRENTLY`; przerwana budowa zostawia INVALID pod tą samą
 *    nazwą, więc przed budową go zdejmujemy.
 *
 * WYCOFANIE ODMAWIA, GDY ZGUBIŁOBY AUTORSTWO (D-088)
 * `up()` umie odtworzyć tylko „dodał właściciel". Jeśli choć jedna pozycja
 * ma innego autora (współpracownik) albo NULL (konto usunięte), cofnięcie
 * i ponowna migracja przypisałyby ją po cichu właścicielowi — czyli
 * nieprawdę o tym, kto co zrobił. Wtedy `down()` przerywa. Na bazie, gdzie
 * wszystko dodał właściciel, cofa się bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const FK = 'collection_items_added_by_fk';

    private const INDEKS = 'collection_items_added_by_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE collection_items ADD COLUMN IF NOT EXISTS added_by_id uuid NULL');

        DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS '.self::FK);
        DB::statement('ALTER TABLE collection_items ADD CONSTRAINT '.self::FK
            .' FOREIGN KEY (added_by_id) REFERENCES users (id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE collection_items VALIDATE CONSTRAINT '.self::FK);

        // Wiersze sprzed współdzielenia dodał właściciel — jedyna osoba,
        // która mogła. Tylko puste, więc ponowienie po awarii niczego nie
        // nadpisuje.
        DB::statement(<<<'SQL'
            UPDATE collection_items ci
               SET added_by_id = c.owner_id
              FROM collections c
             WHERE c.id = ci.collection_id
               AND ci.added_by_id IS NULL
            SQL);

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON collection_items (added_by_id) WHERE added_by_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->odmowJesliZgubiAutorstwo();

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
        DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS '.self::FK);
        DB::statement('ALTER TABLE collection_items DROP COLUMN IF EXISTS added_by_id');
    }

    private function odmowJesliZgubiAutorstwo(): void
    {
        $kolumna = DB::selectOne(
            'SELECT 1 AS jest FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['collection_items', 'added_by_id'],
        );

        if ($kolumna === null) {
            return;
        }

        $ile = (int) DB::selectOne(<<<'SQL'
            SELECT count(*) AS ile
              FROM collection_items ci
              JOIN collections c ON c.id = ci.collection_id
             WHERE ci.added_by_id IS DISTINCT FROM c.owner_id
            SQL)->ile;

        if ($ile === 0) {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji zgubi informację, kto dodał pozycje we wspólnych zeszytach.
            Liczba pozycji dodanych przez kogoś innego niż właściciel (albo przez konto usunięte): {$ile}.
            Ponowna migracja przypisałaby je wszystkie właścicielowi — to byłaby nieprawda.

            Zanim cofniesz:
              1. zrób kopię: CREATE TABLE collection_items_autorzy_kopia AS SELECT collection_id, recipe_id, post_id, added_by_id FROM collection_items;
              2. cofnij najpierw migrację współdzielenia zeszytów tylko wtedy, gdy naprawdę musisz — błąd w ekranie naprawia się bez ruszania bazy;
              3. po odtworzeniu kolumny przywróć autorstwo z kopii.
            TEKST);
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
