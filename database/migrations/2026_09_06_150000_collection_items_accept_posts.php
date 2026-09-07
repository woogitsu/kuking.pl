<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zeszyt przyjmuje też WPISY, nie tylko przepisy (UI kit v2, ekran 01).
 *
 * PO CO
 * Karta wpisu ma w kicie przycisk „Zapisz". Do tej pory zeszyt przyjmował
 * wyłącznie przepisy, więc ten przycisk nie miał gdzie zapisywać. Ludzie
 * odkładają cudze zdjęcia jako inspirację — „chcę kiedyś zrobić coś takiego" —
 * i to jest inna potrzeba niż odłożenie gotowego przepisu z listą składników.
 *
 * WZORZEC JEST TEN SAM CO PRZY KOMENTARZACH
 * Dwie kolumny dopuszczające NULL i CHECK `num_nonnulls(...) = 1`, dokładnie
 * jak `comments_single_target_check` (migracja 2026_09_05_000700). Nie
 * polimorfizm z `item_type`/`item_id`: tamten zapis nie ma kluczy obcych, więc
 * skasowany wpis zostawia w zeszycie wiersz wskazujący w próżnię, a baza nie
 * ma jak tego zauważyć.
 *
 * KLUCZ GŁÓWNY MUSI SIĘ ZMIENIĆ
 * Był `(collection_id, recipe_id)`. Kolumna klucza głównego nie może być NULL,
 * więc przy `recipe_id` dopuszczającym NULL taki klucz jest niemożliwy.
 * Zastępują go DWA indeksy częściowe — po jednym na każdy rodzaj pozycji —
 * które pilnują dokładnie tego samego: ten sam przepis (albo ten sam wpis)
 * nie stanie w tym samym zeszycie dwa razy (issue #43).
 *
 * ROLLBACK ODMAWIA WYKONANIA, ZAMIAST KASOWAĆ (audyt F-02)
 * Stary klucz główny `(collection_id, recipe_id)` nie dopuszcza NULL, więc
 * wiersze z zapisanymi wpisami nie mają jak przetrwać cofnięcia. Pierwsza
 * wersja tego pliku po prostu je usuwała — z komentarzem, że to „utrata danych,
 * świadoma i jedyna możliwa".
 *
 * Świadoma nie znaczy dopuszczalna. `php artisan migrate:rollback` wpisuje się
 * odruchowo, zwykle w pośpiechu i zwykle wtedy, gdy coś już poszło nie tak —
 * a jedyne ostrzeżenie stało w komentarzu, którego w takiej chwili nikt nie
 * czyta. Zeszyt to rzecz, którą człowiek budował miesiącami; skasowanie go
 * przy cofaniu MIGRACJI jest utratą danych bez związku z tym, co się psuło.
 *
 * Dlatego `down()` sprawdza, czy są takie wiersze, i jeśli są — PRZERYWA
 * z instrukcją, co zrobić. Gdy ich nie ma (świeże środowisko, staging tuż po
 * migracji, testy), cofa się normalnie i bez pytania.
 *
 * Wymuszenie: `KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY=1`. Zmienna, nie flaga
 * artisana, bo ma być czynnością osobną i zapamiętaną — nie czymś, co da się
 * dopisać do polecenia, którego i tak się nie doczytało.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS collection_items_pkey');
        }

        Schema::table('collection_items', function (Blueprint $table): void {
            $table->foreignUuid('post_id')->nullable()->after('recipe_id')->constrained('posts')->cascadeOnDelete();
        });

        // Dopiero teraz `recipe_id` może dopuszczać NULL — wcześniej trzymał
        // go klucz główny.
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE collection_items ALTER COLUMN recipe_id DROP NOT NULL');
            DB::statement('ALTER TABLE collection_items ADD CONSTRAINT collection_items_single_target_check CHECK (num_nonnulls(recipe_id, post_id) = 1)');
            DB::statement('CREATE UNIQUE INDEX collection_items_recipe_unique ON collection_items (collection_id, recipe_id) WHERE recipe_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX collection_items_post_unique ON collection_items (collection_id, post_id) WHERE post_id IS NOT NULL');
            DB::statement('CREATE INDEX collection_items_post_idx ON collection_items (post_id)');
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS collection_items_post_idx');
            DB::statement('DROP INDEX IF EXISTS collection_items_post_unique');
            DB::statement('DROP INDEX IF EXISTS collection_items_recipe_unique');
            DB::statement('ALTER TABLE collection_items DROP CONSTRAINT IF EXISTS collection_items_single_target_check');

            $this->upewnijSieZeWolnoKasowacZapisaneWpisy();

            // Dochodzimy tu wyłącznie wtedy, gdy nie ma czego stracić albo gdy
            // człowiek świadomie się na to zgodził zmienną środowiskową.
            DB::statement('DELETE FROM collection_items WHERE post_id IS NOT NULL');
        }

        Schema::table('collection_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('post_id');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE collection_items ALTER COLUMN recipe_id SET NOT NULL');
            DB::statement('ALTER TABLE collection_items ADD PRIMARY KEY (collection_id, recipe_id)');
        }
    }

    /**
     * Przerywa cofanie, jeśli w zeszytach leżą zapisane wpisy.
     *
     * Liczba w komunikacie jest ważniejsza, niż wygląda: „ktoś coś straci" nie
     * skłania do zatrzymania się, „stracisz 1 483 zapisane wpisy" owszem.
     */
    private function upewnijSieZeWolnoKasowacZapisaneWpisy(): void
    {
        $ile = (int) DB::table('collection_items')->whereNotNull('post_id')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, NIE `env()`. Na produkcji konfiguracja jest zbuforowana
        // (`config:cache`), a wtedy `env()` zwraca `null` — czyli furtka nie
        // zadziałałaby dokładnie tam, gdzie jest potrzebna, i wyglądałoby to
        // jak awaria migracji. `getenv()` czyta środowisko procesu i buforowanie
        // konfiguracji go nie dotyczy. Złapał to PHPStan.
        if (getenv('KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY') === '1') {
            return;
        }

        $instrukcja = <<<TEKST
            Cofnięcie tej migracji skasuje {$ile} zapisanych wpisów z zeszytów — bezpowrotnie.
            Stary klucz główny nie dopuszcza NULL w `recipe_id`, więc te wiersze nie mają jak przetrwać.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE collection_items_kopia AS TABLE collection_items;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — problem z widokiem
                 albo z akcją zapisu naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY=1.

            Na świeżym środowisku, gdzie nikt jeszcze nic nie zapisał, cofnięcie działa bez pytania.
            TEKST;

        throw new RuntimeException($instrukcja);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
