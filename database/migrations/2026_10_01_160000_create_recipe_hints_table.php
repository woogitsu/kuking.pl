<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Wskazówki od gotujących” (#2352, decyzja właściciela z 1 października
 * 2026, D-333): autor przepisu proponuje, żeby uwaga z czyjegoś wykonania
 * („Ugotowałem” z notatką) stała przy jego przepisie jako wskazówka. Kucharz
 * dostaje prośbę i odpowiada „Zgadzam się” albo „Nie” — brak odpowiedzi to
 * brak publikacji (zgoda NA WNIOSEK, nie z góry). Zgodę można wycofać w każdej
 * chwili i wskazówka znika.
 *
 * Wiersz NIE kopiuje tekstu. Tekst wskazówki to `cooked_events.note`, które
 * jest niezmienne (nie ma edycji wykonania), więc pokazujemy go z wykonania:
 * jedna kopia danych kucharza zamiast dwóch, usunięcie wykonania zabiera
 * wskazówkę (`ON DELETE CASCADE`), a moderacja wykonania działa też na wskazówkę.
 *
 * `cooked_event_id` jest UNIKALNE: jedno wykonanie ma najwyżej jedną
 * wskazówkę w całym swoim życiu. To jest świadome: „Nie” i wycofanie są
 * ostateczne, więc autor nie może ponawiać prośby (presja na kucharza).
 *
 * Stany (`status`): proposed → accepted | declined; accepted → withdrawn.
 * Pola `decided_at` i `withdrawn_at` muszą zgadzać się ze stanem — pilnuje
 * tego CHECK, nie tylko kod. `recipe_version_number` to numer wersji przepisu
 * z chwili prośby (bez klucza obcego: wersje podlegają retencji).
 *
 * Tabela jest NOWA, więc CHECK-i i klucze obce wchodzą razem z `CREATE TABLE`
 * (AGENTS.md §6: nowa tabela nie potrzebuje `NOT VALID` + `VALIDATE`).
 *
 * ROLLBACK: `down()` kasuje tabelę razem z odpowiedziami ludzi („Nie” i
 * wycofanie to decyzje, których nie da się odtworzyć — po ponownym `migrate`
 * autor mógłby prosić drugi raz), więc ODMAWIA, gdy w tabeli są wiersze
 * (D-088). Na pustej bazie (CI, `migrate:refresh`) przechodzi bez pytania.
 * Wymuszenie po kopii tabeli: `KUKING_ROLLBACK_KASUJE_WSKAZOWKI=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_hints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('cooked_event_id')->constrained('cooked_events')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('cook_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10);
            $table->unsignedInteger('recipe_version_number')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE recipe_hints ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE recipe_hints ADD CONSTRAINT recipe_hints_status_check
            CHECK (status IN ('proposed', 'accepted', 'declined', 'withdrawn'))");
        // Stan i znaczniki czasu mówią to samo (pilnuje baza, nie tylko akcje).
        DB::statement("ALTER TABLE recipe_hints ADD CONSTRAINT recipe_hints_stan_spojny_check CHECK (
            (status = 'proposed' AND decided_at IS NULL AND withdrawn_at IS NULL)
            OR (status IN ('accepted', 'declined') AND decided_at IS NOT NULL AND withdrawn_at IS NULL)
            OR (status = 'withdrawn' AND decided_at IS NOT NULL AND withdrawn_at IS NOT NULL)
        )");
        // Autor nie prosi sam siebie (własnego wykonania nie ma po co „zgadzać”).
        DB::statement('ALTER TABLE recipe_hints ADD CONSTRAINT recipe_hints_autor_nie_kucharz_check
            CHECK (author_id <> cook_id)');
        DB::statement('ALTER TABLE recipe_hints ADD CONSTRAINT recipe_hints_wersja_check
            CHECK (recipe_version_number IS NULL OR recipe_version_number >= 1)');
        // Jedno wykonanie — jedna wskazówka w całym życiu (patrz komentarz wyżej).
        DB::statement('CREATE UNIQUE INDEX recipe_hints_cooked_event_unique ON recipe_hints (cooked_event_id)');
        // Strona przepisu: przyjęte wskazówki jednego przepisu, najstarsze pierwsze.
        DB::statement("CREATE INDEX recipe_hints_przyjete_idx ON recipe_hints (recipe_id, decided_at, id) WHERE status = 'accepted'");
        // Klucz obcy bez indeksu to pełny skan przy kasowaniu przepisu.
        DB::statement('CREATE INDEX recipe_hints_recipe_idx ON recipe_hints (recipe_id)');
        // Kucharz: prośby czekające na odpowiedź, eksport, wymazanie konta.
        DB::statement('CREATE INDEX recipe_hints_cook_idx ON recipe_hints (cook_id, status)');
        // Autor: limity i eksport.
        DB::statement('CREATE INDEX recipe_hints_author_idx ON recipe_hints (author_id, created_at)');
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_hints')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('recipe_hints');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('recipe_hints')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_WSKAZOWKI') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje odpowiedzi ludzi na prośby o wskazówki — bezpowrotnie.
            Liczba wierszy, które znikną: {$ile}.

            Po ponownej migracji wszystkie wskazówki zniknęłyby ze stron przepisów, a autorzy
            mogliby prosić o zgodę jeszcze raz także osoby, które odpowiedziały „Nie”
            albo wycofały zgodę.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE recipe_hints_kopia AS SELECT * FROM recipe_hints;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu
                 naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_WSKAZOWKI=1.

            Na świeżym środowisku albo przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }
};
