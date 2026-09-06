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
 * ROLLBACK — I TU JEST PUŁAPKA
 * `down()` przywraca stary klucz główny, więc MUSI najpierw usunąć wiersze
 * z `post_id`. To jest utrata danych: zapisane wpisy znikają z zeszytów
 * bezpowrotnie. Przy cofaniu na produkcji najpierw kopia tabeli.
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

            // UTRATA DANYCH, ŚWIADOMA I JEDYNA MOŻLIWA.
            // Stary klucz główny nie dopuszcza NULL w `recipe_id`, więc wiersze
            // z zapisanymi wpisami nie mają jak przetrwać cofnięcia.
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

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
