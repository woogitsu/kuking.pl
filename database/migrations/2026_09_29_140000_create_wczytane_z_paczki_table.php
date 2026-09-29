<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ślad „ta treść przyszła z własnej paczki eksportu” — idempotencja importu (#1985, etap 2).
 *
 * CO TU JEST
 * Jeden wiersz na jedną wczytaną pozycję (przepis, wpis albo zeszyt). Trzyma
 * `odcisk` — SHA-256 z ujednoliconej treści pozycji (liczy go
 * `PodgladPaczkiEksportu`, ten sam co w podglądzie) — i wskazuje utworzoną
 * treść. Ponowne wczytanie tej samej paczki trafia w `UNIQUE (user_id, odcisk)`
 * i niczego nie dubluje, także gdy dwa żądania idą naraz.
 *
 * CZEGO TU NIE MA
 * Treści z paczki. Ani jednego znaku przepisu, wpisu czy nazwy zeszytu — to
 * jest skrót i wskaźnik. Dziennik i ta tabela nie utrwalają zawartości paczki
 * (issue #1985: „audytowalny bez utrwalania całej zawartości”).
 *
 * KLUCZE OBCE
 * Wskaźnik na treść ma `ON DELETE CASCADE`: twarde skasowanie treści zabiera
 * ślad. Skasowanie MIĘKKIE (`deleted_at`) śladu nie rusza, więc `WczytajPaczke`
 * sam rozpoznaje ślad po skasowanej treści i pozwala wczytać ją jeszcze raz.
 *
 * ROLLBACK
 * `down()` usuwa tabelę. Przepisy, wpisy i zeszyty zostają — ale znika pamięć
 * o tym, co już wczytano, więc ponowne wczytanie starej paczki utworzyłoby
 * duplikaty. Dlatego `down()` ODMAWIA, gdy w tabeli jest choć jeden wiersz
 * (D-088; odmowa wąska — pusta tabela i CI z `migrate:refresh` przechodzą bez
 * pytania). Wymuszenie po świadomej decyzji: `KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wczytane_z_paczki', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('rodzaj', 10);
            $table->char('odcisk', 64);
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('collection_id')->nullable()->constrained('collections')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE wczytane_z_paczki ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE wczytane_z_paczki ADD CONSTRAINT wczytane_z_paczki_rodzaj_check CHECK (rodzaj IN ('przepis','wpis','zeszyt'))");
            DB::statement("ALTER TABLE wczytane_z_paczki ADD CONSTRAINT wczytane_z_paczki_odcisk_check CHECK (odcisk ~ '^[0-9a-f]{64}\$')");
            // Rodzaj i wskaźnik muszą się zgadzać: wiersz wskazuje DOKŁADNIE jedną treść, właściwego rodzaju.
            DB::statement(<<<'SQL'
                ALTER TABLE wczytane_z_paczki ADD CONSTRAINT wczytane_z_paczki_cel_check CHECK (
                    (rodzaj = 'przepis' AND recipe_id IS NOT NULL AND post_id IS NULL AND collection_id IS NULL)
                    OR (rodzaj = 'wpis' AND post_id IS NOT NULL AND recipe_id IS NULL AND collection_id IS NULL)
                    OR (rodzaj = 'zeszyt' AND collection_id IS NOT NULL AND recipe_id IS NULL AND post_id IS NULL)
                )
                SQL);
            DB::statement('ALTER TABLE wczytane_z_paczki ADD CONSTRAINT wczytane_z_paczki_user_odcisk_unique UNIQUE (user_id, odcisk)');
            DB::statement('CREATE INDEX wczytane_z_paczki_recipe_idx ON wczytane_z_paczki (recipe_id) WHERE recipe_id IS NOT NULL');
            DB::statement('CREATE INDEX wczytane_z_paczki_post_idx ON wczytane_z_paczki (post_id) WHERE post_id IS NOT NULL');
            DB::statement('CREATE INDEX wczytane_z_paczki_collection_idx ON wczytane_z_paczki (collection_id) WHERE collection_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wczytane_z_paczki')) {
            $this->upewnijSieZeWolnoKasowacSlady();
        }

        Schema::dropIfExists('wczytane_z_paczki');
    }

    private function upewnijSieZeWolnoKasowacSlady(): void
    {
        $ile = (int) DB::table('wczytane_z_paczki')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null
        // (ten sam powód co w `2026_09_28_233700_create_pantry_items_table`).
        if (getenv('KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje pamięć o treściach wczytanych z paczek eksportu.
            Liczba śladów, które znikną: {$ile}.

            Same przepisy, wpisy i zeszyty zostają, ale ponowne wczytanie starej paczki
            utworzyłoby je jeszcze raz (duplikaty).

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE wczytane_z_paczki_kopia AS SELECT * FROM wczytane_z_paczki;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — błąd w ekranie
                 wczytywania naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU=1.

            Na świeżym środowisku, gdzie nikt jeszcze niczego nie wczytał, cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
