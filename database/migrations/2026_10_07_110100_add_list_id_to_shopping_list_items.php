<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shopping_list_items.list_id` — do której nazwanej listy należy pozycja (#2528).
 *
 * `NULL` = LISTA DOMYŚLNA („Na co dzień”): wszystkie dotychczasowe pozycje
 * zostają na niej bez żadnego backfillu, z tekstem, kolejnością, odhaczeniem
 * i pochodzeniem. Wartość to `shopping_lists.id` listy tej samej osoby
 * (tego pilnuje `ListaZakupow` pod blokadą konta i `ShoppingListPolicy`;
 * baza pilnuje, że lista istnieje).
 *
 * Klucz obcy `ON DELETE CASCADE`: usunięcie nazwanej listy kasuje jej
 * pozycje — po jawnym potwierdzeniu na ekranie (liczba pozycji w pytaniu).
 *
 * DDL na ISTNIEJĄCEJ tabeli (AGENTS.md §6): kolumna bez klucza i bez
 * wartości domyślnej (`ADD COLUMN` nullable nie przepisuje tabeli), klucz
 * obcy `NOT VALID` + osobno `VALIDATE CONSTRAINT`, indeks
 * `CREATE INDEX CONCURRENTLY` — stąd `$withinTransaction = false`.
 *
 * ROLLBACK: `down()` zdejmuje kolumnę, ale ODMAWIA, gdy choć jedna pozycja
 * ma `list_id` (D-088): bez kolumny pozycje z listy „Święta” wpadłyby po
 * cichu na listę domyślną, mieszając zakupy na okazję z dzisiejszym obiadem.
 * Pusta baza, CI i brak nazwanych list przechodzą bez pytania. Wymuszenie
 * po kopii tabeli: `KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW=1`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const KLUCZ = 'shopping_list_items_list_id_foreign';

    private const INDEKS = 'shopping_list_items_list_idx';

    public function up(): void
    {
        DB::statement('ALTER TABLE shopping_list_items ADD COLUMN IF NOT EXISTS list_id uuid NULL');

        DB::statement('ALTER TABLE shopping_list_items DROP CONSTRAINT IF EXISTS '.self::KLUCZ);
        DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT '.self::KLUCZ
            .' FOREIGN KEY (list_id) REFERENCES shopping_lists (id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE shopping_list_items VALIDATE CONSTRAINT '.self::KLUCZ);

        // Na produkcji (`$withinTransaction = false`) indeks idzie CONCURRENTLY; w teście
        // w transakcji (`RefreshDatabase`) CONCURRENTLY jest niedozwolone, więc zwykły.
        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        // Przerwana budowa zostawia indeks INVALID pod tą samą nazwą — zdejmujemy go przed budową.
        DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON shopping_list_items (list_id) WHERE list_id IS NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopping_list_items', 'list_id')) {
            $this->upewnijSieZeWolnoZdjac();
        }

        DB::statement('DROP INDEX '.(DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '').'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE shopping_list_items DROP CONSTRAINT IF EXISTS '.self::KLUCZ);
        DB::statement('ALTER TABLE shopping_list_items DROP COLUMN IF EXISTS list_id');
    }

    private function upewnijSieZeWolnoZdjac(): void
    {
        $ile = (int) DB::table('shopping_list_items')->whereNotNull('list_id')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji wrzuciłoby pozycje z nazwanych list zakupów na listę domyślną,
            mieszając zakupy na różne okazje ze zwykłą listą. Liczba takich pozycji: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabel:
                 CREATE TABLE shopping_lists_kopia AS SELECT * FROM shopping_lists;
                 CREATE TABLE shopping_list_items_kopia AS SELECT * FROM shopping_list_items;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu list
                 naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW=1.

            Na świeżym środowisku albo gdy żadna pozycja nie jest na nazwanej liście, cofnięcie działa bez pytania.
            TEKST);
    }
};
