<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Krótkotrwałe cofnięcie omyłkowego usunięcia z listy zakupów (#2630,
 * rozszerzenie D-333; decyzja właściciela z 2 października 2026).
 *
 * Jeden wiersz to JEDNA ostatnia operacja usunięcia jednej osoby („Usuń”
 * przy pozycji albo „Wyczyść odhaczone”). Kolejne usunięcie zastępuje
 * poprzedni wiersz — nie ma archiwum ani historii zakupów. `user_id` jest
 * UNIQUE, więc dwa równoległe usunięcia nie zostawią dwóch wierszy.
 *
 * `items` to migawka usuniętych pozycji (tekst, pochodzenie, przepis,
 * kolejność, odhaczenie, daty) jako tablica JSON. Migawka NIE ma kluczy
 * obcych do przepisów: cofnięcie sprawdza w chwili przywracania, czy
 * przepis jeszcze istnieje, i jeśli nie, przywraca sam tekst (kontrakt
 * z `shopping_list_items.recipe_id ON DELETE SET NULL`). Wiersz nie
 * zawiera tytułu, linku ani zdjęcia przepisu.
 *
 * `expires_at` — po tej chwili wiersz jest nieużyteczny (cofnięcie go
 * odrzuca) i jest kasowany: przy następnym usunięciu albo odczycie listy
 * przez tę osobę oraz zadaniem `kuking:sprzataj-cofniecia-zakupow` co
 * kwadrans. Czas: `kuking.zakupy.cofniecie_minut`.
 *
 * Tabela jest NOWA, więc CHECK-i i klucz obcy wchodzą razem z `CREATE
 * TABLE` (AGENTS.md §6).
 *
 * ROLLBACK: `down()` kasuje tabelę razem z materiałem oczekującym na
 * cofnięcie, więc ODMAWIA, gdy w tabeli są ŚWIEŻE (niewygasłe) wiersze
 * (D-088). Wygasłe wiersze i tak są do skasowania, więc nie blokują. Na
 * świeżej bazie (CI, `migrate:refresh`) przechodzi bez pytania. Wymuszenie:
 * `KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_undos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('scope', 10);
            $table->jsonb('items');
            $table->unsignedSmallInteger('items_count');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
        });

        DB::statement('ALTER TABLE shopping_list_undos ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE shopping_list_undos ADD CONSTRAINT shopping_list_undos_scope_check
            CHECK (scope IN ('single', 'checked'))");
        DB::statement("ALTER TABLE shopping_list_undos ADD CONSTRAINT shopping_list_undos_items_check
            CHECK (jsonb_typeof(items) = 'array' AND jsonb_array_length(items) = items_count AND items_count >= 1)");
        DB::statement('CREATE INDEX shopping_list_undos_expires_idx ON shopping_list_undos (expires_at)');
    }

    public function down(): void
    {
        if (Schema::hasTable('shopping_list_undos')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('shopping_list_undos');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('shopping_list_undos')->where('expires_at', '>', now())->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje usunięte pozycje list zakupów, które ludzie
            mogą jeszcze odzyskać przyciskiem „Cofnij usunięcie”. Liczba osób: {$ile}.

            Zanim cofniesz:
              1. poczekaj, aż okno cofnięcia minie (domyślnie 15 minut) — wiersze wygasną
                 i przestaną blokować;
              2. albo zrób kopię tabeli:
                 CREATE TABLE shopping_list_undos_kopia AS SELECT * FROM shopping_list_undos;
              3. jeśli naprawdę trzeba, uruchom ponownie z KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW=1.

            Na świeżym środowisku albo przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }
};
