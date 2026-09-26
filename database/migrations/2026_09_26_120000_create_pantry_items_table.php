<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Co mam w domu” — prywatna lista produktów jednej osoby (V2, D-285).
 *
 * CO TU JEST
 * Tabela `pantry_items` (jeden wiersz = jeden produkt, który ktoś ma
 * w domu) i funkcja `public.kuking_rdzenie_skladnika(text)`, która z nazwy
 * produktu albo z linijki składnika w przepisie robi tablicę RDZENI słów.
 * Na tych rdzeniach stoi „Co ugotuję z tego, co mam” (`App\Domain\Pantry\CoUgotuje`):
 * produkt z listy „jest” w przepisie, gdy każdy jego rdzeń występuje
 * w linijce składnika (`rdzenie <@ kuking_rdzenie_skladnika(ingredient_text)`).
 *
 * DLACZEGO FUNKCJA W BAZIE, A NIE NORMALIZACJA W PHP
 * Ta sama reguła musi być policzona po obu stronach porównania: przy
 * produkcie z listy i przy każdej linijce składnika w każdym przepisie.
 * Linijki składników są w bazie i porównanie idzie w SQL, więc reguła
 * mieszka w SQL — RAZ. Kolumny `rdzenie` i `klucz` są generowane z tej
 * samej funkcji, więc nie ma drugiej kopii reguły w PHP, która mogłaby się
 * rozjechać przy pierwszej zmianie (ten błąd repozytorium już zna — patrz
 * nagłówek `tests/Support/kuking_nazwa_testowej_bazy.php`).
 *
 * REGUŁA (celowo prosta, bez AI i bez słownika odmian):
 *   1. `kuking_normalize()` — małe litery, bez polskich znaków (ta sama
 *      funkcja co w wyszukiwarce);
 *   2. wszystko poza literami i cyframi → spacja, podział na słowa;
 *   3. odpadają słowa jednoliterowe i same liczby („2 cebule”, „500 g”);
 *   4. „liczba mnoga prosta”: słowo dłuższe niż 5 liter na „-ow” traci „ow”
 *      (pomidorów → pomidor), słowo dłuższe niż 3 litery traci końcową
 *      samogłoskę (cebula/cebule → cebul, jajka/jajko → jajk, mąka/mąki → mak).
 * Znane skutki uboczne tej prostoty są wypisane w D-285 (np. „mąka” i „mak”
 * mają ten sam rdzeń, „mleko” pasuje do „mleko kokosowe”).
 *
 * `klucz` (rdzenie posortowane i sklejone spacją) pilnuje, żeby ta sama
 * osoba nie miała na liście dwa razy tego samego produktu w dwóch
 * pisowniach („Jajka” i „jajko”) — `UNIQUE (user_id, klucz)`.
 *
 * PRYWATNOŚĆ
 * Lista jest widoczna wyłącznie dla właściciela (`PantryItemPolicy`), jest
 * w paczce danych (`InwentarzDanychKonta`, sekcja `co_mam_w_domu`)
 * i znika przy wymazaniu konta (`EraseAccountData`). Klucz obcy ma
 * `ON DELETE CASCADE` na wypadek twardego usunięcia wiersza `users`
 * (konta się anonimizuje, nie kasuje — D-022 — więc to jest pas bezpieczeństwa,
 * a nie główna droga).
 *
 * ROLLBACK
 * `down()` usuwa tabelę i obie funkcje. Nie rusza niczego poza nimi — przepisy,
 * składniki i wyszukiwarka zostają takie jak przed tą migracją. Ale lista
 * „Co mam w domu” to dane wpisane przez ludzi, a cofnięcie kasuje je
 * bezpowrotnie. Dlatego `down()` ODMAWIA, gdy w tabeli jest choć jeden
 * wiersz (D-088: odmowa wąska — świeża baza i CI z `migrate:refresh`
 * przechodzą bez pytania). Wymuszenie po zrobieniu kopii:
 * `KUKING_ROLLBACK_KASUJE_SPIZARNIE=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.kuking_rdzenie_skladnika(text) RETURNS text[]
            AS $$
                SELECT COALESCE(array_agg(DISTINCT CASE
                        WHEN length(s) > 5 AND s LIKE '%ow' THEN left(s, -2)
                        WHEN length(s) > 3 THEN regexp_replace(s, '[aeiouy]$', '')
                        ELSE s
                    END), '{}')
                FROM regexp_split_to_table(
                    regexp_replace(public.kuking_normalize($1), '[^a-z0-9]+', ' ', 'g'), ' '
                ) AS s
                WHERE length(s) >= 2 AND s !~ '^[0-9]+$'
            $$
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            SQL);

        // Klucz porównania całej nazwy: rdzenie (już posortowane przez
        // `array_agg(DISTINCT …)`) sklejone spacją. Osobna funkcja, bo
        // `array_to_string()` jest w PostgreSQL oznaczone jako STABLE
        // i nie wolno go użyć wprost w kolumnie generowanej.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.kuking_klucz_skladnika(text) RETURNS text
            AS $$ SELECT array_to_string(public.kuking_rdzenie_skladnika($1), ' ') $$
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            SQL);

        Schema::create('pantry_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Nazwa dokładnie tak, jak ją wpisał człowiek — to ją pokazujemy.
            $table->string('name', 120);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE pantry_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE pantry_items ADD COLUMN rdzenie text[] '
            .'GENERATED ALWAYS AS (public.kuking_rdzenie_skladnika(name)) STORED',
        );
        DB::statement(
            'ALTER TABLE pantry_items ADD COLUMN klucz text '
            .'GENERATED ALWAYS AS (public.kuking_klucz_skladnika(name)) STORED',
        );

        // Nazwa bez ani jednego słowa, które da się porównać („500”, „-”),
        // nie pasowałaby do żadnego przepisu — albo, gorzej, do każdego
        // (pusta tablica zawiera się w każdej). CHECK w bazie, bo `<@` z pustą
        // tablicą to cichy błąd: „masz wszystkie składniki” przy każdym przepisie.
        DB::statement(
            'ALTER TABLE pantry_items ADD CONSTRAINT pantry_items_name_check '
            .'CHECK (char_length(btrim(name)) BETWEEN 2 AND 120 AND cardinality(rdzenie) > 0)',
        );

        DB::statement('ALTER TABLE pantry_items ADD CONSTRAINT pantry_items_user_klucz_unique UNIQUE (user_id, klucz)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if (Schema::hasTable('pantry_items')) {
            $this->upewnijSieZeWolnoKasowacListy();
        }

        Schema::dropIfExists('pantry_items');
        DB::statement('DROP FUNCTION IF EXISTS public.kuking_klucz_skladnika(text)');
        DB::statement('DROP FUNCTION IF EXISTS public.kuking_rdzenie_skladnika(text)');
    }

    private function upewnijSieZeWolnoKasowacListy(): void
    {
        $ile = (int) DB::table('pantry_items')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null
        // (ten sam powód co w `2026_09_06_150000_collection_items_accept_posts`).
        if (getenv('KUKING_ROLLBACK_KASUJE_SPIZARNIE') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje listy „Co mam w domu” — bezpowrotnie.
            Liczba produktów, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE pantry_items_kopia AS SELECT id, user_id, name, created_at FROM pantry_items;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — błąd w ekranie
                 „Co ugotuję?” naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_SPIZARNIE=1.

            Na świeżym środowisku, gdzie nikt jeszcze nic nie wpisał, cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
