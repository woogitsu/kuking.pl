<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lista zakupów — etap 2 z #27 (decyzja właściciela z 29 września 2026,
 * D-333: budować bez czekania na pomiar planera z D-310).
 *
 * Jeden wiersz to jedna pozycja prywatnej listy jednej osoby. Pozycja jest
 * TEKSTEM: albo dopisaną ręcznie (`source = 'manual'`), albo skopiowaną
 * z przepisu (`source = 'recipe'`) — dosłownie, linia po linii, bez sumowania
 * i łączenia (składnik jest u nas wolnym tekstem, `ingredient_text`).
 *
 * `recipe_id` mówi tylko, Z KTÓREGO przepisu skopiowano linię, i służy do
 * ostrzeżenia przy ponownym dodaniu tego samego przepisu. Klucz obcy ma
 * `ON DELETE SET NULL`: twarde usunięcie przepisu nie kasuje listy zakupów
 * ani nie odbiera pozycji tekstu — kolumna `source` zostaje `recipe`, więc
 * ekran wie, że pozycja pochodziła z przepisu, który już nie istnieje.
 * Przepis ukryty albo zawężony zostaje z kluczem, ale ekran nie pokazuje
 * jego tytułu (patrz `ListaZakupow`) — pozycja zostaje samym tekstem.
 *
 * Tabela jest NOWA, więc CHECK-i i klucze obce wchodzą razem z `CREATE
 * TABLE`: nikt jeszcze na nią nie czeka i `NOT VALID` + `VALIDATE` nie ma tu
 * czego odciążać (AGENTS.md §6, „Nowa tabela tych reguł nie potrzebuje”).
 *
 * `position` porządkuje pozycje w kolejności dopisywania (kolejność linii
 * przepisu jest częścią przepisu). `checked_at` NULL = do kupienia; data =
 * odhaczone (kiedy).
 *
 * ROLLBACK: `down()` kasuje tabelę razem z listami ludzi, więc ODMAWIA, gdy
 * w tabeli są wiersze (D-088). Na świeżej i pustej bazie (CI,
 * `migrate:refresh`) przechodzi bez pytania. Wymuszenie po kopii tabeli:
 * `KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 240);
            $table->string('source', 10);
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE shopping_list_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_source_check
            CHECK (source IN ('manual', 'recipe'))");
        DB::statement("ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_recipe_source_check
            CHECK (recipe_id IS NULL OR source = 'recipe')");
        DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_text_check
            CHECK (char_length(btrim(text)) BETWEEN 1 AND 240)');
        DB::statement('CREATE INDEX shopping_list_items_user_position_idx ON shopping_list_items (user_id, position)');
        // Klucz obcy bez indeksu to pełny skan przy kasowaniu przepisu.
        DB::statement('CREATE INDEX shopping_list_items_recipe_idx ON shopping_list_items (recipe_id) WHERE recipe_id IS NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasTable('shopping_list_items')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('shopping_list_items');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('shopping_list_items')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje listy zakupów ludzi — bezpowrotnie.
            Liczba pozycji, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE shopping_list_items_kopia AS SELECT * FROM shopping_list_items;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu listy
                 naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW=1.

            Na świeżym środowisku albo przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }
};
