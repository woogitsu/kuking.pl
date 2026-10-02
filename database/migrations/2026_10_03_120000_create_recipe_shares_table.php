<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jawne udostępnienie jednego przepisu wskazanej osobie (#2650; decyzja
 * właściciela z 2 października 2026, wiersz w D-333).
 *
 * Jeden wiersz = „autor przepisu pozwolił TEJ osobie CZYTAĆ TEN przepis".
 * Nic więcej: bez komentowania, „Ugotowałem", własnej wersji, zeszytu,
 * historii wersji i skanu kartki. Udostępnienie nie zmienia
 * `recipes.visibility` — przepis „Tylko ja" dalej jest „Tylko ja" dla całego
 * serwisu, feedu, wyszukiwarki, mapy strony i JSON-LD.
 *
 * Autor nie ma osobnej kolumny: jest nim zawsze `recipes.author_id`.
 * Druga kopia autora mogłaby się z nim rozjechać.
 *
 * - `recipe_id` → `recipes` `ON DELETE CASCADE` (trwałe usunięcie przepisu);
 *   zwykłe usunięcie przez autora kasuje udostępnienia jawnie
 *   (`OdbierzDostepDoPrzepisu::wszystkieDlaPrzepisu()`);
 * - `recipient_id` → `users` `ON DELETE CASCADE`; wymazanie konta (wiersz
 *   `users` zostaje jako `erased`) kasuje udostępnienia jawnie
 *   (`KoniecUdostepnienPrzepisow::przyWymazaniu()`);
 * - `UNIQUE (recipe_id, recipient_id)` — jedno udostępnienie na parę,
 *   także przy dwóch równoległych kliknięciach;
 * - indeks `(recipient_id)` — lista „Udostępnione mi" i sprzątanie;
 * - wyzwalacz `recipe_shares_guard` odmawia (`check_violation`) wpisania
 *   AUTORA jako odbiorcy jego własnego przepisu. CHECK tego nie wyrazi, bo
 *   warunek dotyczy wiersza `recipes` (ten sam wzorzec co
 *   `collection_members_guard`).
 *
 * Tabela jest NOWA, więc klucze obce i indeksy wchodzą razem z `CREATE
 * TABLE` (AGENTS.md §6).
 *
 * ROLLBACK (D-088): udostępnienie to decyzja człowieka o prywatności.
 * `down()` kasuje tabelę, więc ODMAWIA, gdy jest w niej choć jeden wiersz —
 * po cofnięciu nikt nie wie, komu autor pokazał przepis, a ponowne
 * `up()` tego nie odtworzy. Na pustej tabeli (CI, `migrate:refresh`)
 * przechodzi bez pytania. Wymuszenie po zrobieniu kopii:
 * `KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_shares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['recipe_id', 'recipient_id'], 'recipe_shares_recipe_recipient_unique');
            $table->index('recipient_id', 'recipe_shares_recipient_idx');
        });

        DB::statement('ALTER TABLE recipe_shares ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION recipe_shares_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM recipes WHERE id = NEW.recipe_id AND author_id = NEW.recipient_id) THEN
                    RAISE EXCEPTION 'Autor przepisu nie może być odbiorcą jego udostępnienia'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER recipe_shares_guard BEFORE INSERT OR UPDATE ON recipe_shares
            FOR EACH ROW EXECUTE FUNCTION recipe_shares_guard()');
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_shares')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('recipe_shares');
        DB::statement('DROP FUNCTION IF EXISTS recipe_shares_guard()');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('recipe_shares')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje udostępnienia przepisów wskazanym osobom
            (kto komu pozwolił czytać który przepis). Liczba udostępnień: {$ile}.
            Ponowne uruchomienie migracji ich nie odtworzy.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE recipe_shares_kopia AS SELECT * FROM recipe_shares;
              2. jeśli naprawdę trzeba, uruchom ponownie z
                 KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1.

            Przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }
};
