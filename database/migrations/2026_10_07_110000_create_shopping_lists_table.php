<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Osobne, nazwane listy zakupów na różne okazje (#2528, V2, D-333 — paczka E).
 *
 * CO TU JEST
 * Tabela `shopping_lists`: DODATKOWA, nazwana prywatna lista zakupów jednej
 * osoby („Święta”, „Przyjęcie u Kasi”). Lista domyślna („Na co dzień”) NIE ma
 * wiersza w tej tabeli: to pozycje bez `list_id` (patrz następna migracja),
 * więc obecne listy ludzi zostają dokładnie tam, gdzie były — bez backfillu,
 * bez przepisywania `shopping_list_items` i bez ryzyka zgubienia pozycji.
 *
 * NAZWA to wolny tekst osoby, czyli NOWA data osobowa (może zawierać imię lub
 * okazję rodzinną) — dlatego jest w paczce danych, w polityce prywatności i
 * kasuje się z kontem. Najwyżej 60 znaków po obcięciu białych znaków (CHECK),
 * niepowtarzalna u jednej osoby bez względu na wielkość liter
 * (`shopping_lists_user_name_lower_unique`).
 *
 * Nowa tabela — reguły `lock_timeout`/`NOT VALID` z AGENTS.md §6 jej nie
 * dotyczą, nikt jeszcze na nią nie czeka. Klucz obcy `user_id` kasuje listy
 * razem z kontem; kasowanie listy kasuje jej pozycje (klucz w drugiej
 * migracji), a o tym, że człowiek to rozumie, decyduje ekran potwierdzenia.
 *
 * ROLLBACK: `down()` usuwa tabelę i ODMAWIA, gdy istnieje choć jedna lista
 * (D-088) — z listą znikłyby nazwy, a pozycje przypisane do niej (po
 * cofnięciu drugiej migracji) wróciłyby na listę domyślną bez śladu, skąd
 * pochodzą. Na świeżej bazie i w CI przechodzi bez pytania. Wymuszenie po
 * kopii tabeli: `KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE shopping_lists ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_name_check
            CHECK (char_length(btrim(name)) BETWEEN 1 AND 60)');
        DB::statement('CREATE UNIQUE INDEX shopping_lists_user_name_lower_unique ON shopping_lists (user_id, lower(name))');
    }

    public function down(): void
    {
        if (Schema::hasTable('shopping_lists')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('shopping_lists');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('shopping_lists')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje nazwane listy zakupów ludzi — bezpowrotnie.
            Liczba list, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabel:
                 CREATE TABLE shopping_lists_kopia AS SELECT * FROM shopping_lists;
                 CREATE TABLE shopping_list_items_kopia AS SELECT * FROM shopping_list_items;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu list
                 naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW=1.

            Na świeżym środowisku albo przy braku nazwanych list cofnięcie działa bez pytania.
            TEKST);
    }
};
