<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prywatny roboczy dopisek podczas gotowania (#2587, V2).
 *
 * CO TU JEST
 * Tabela `cooking_notes`: jeden wiersz = jeden krótki, prywatny dopisek
 * zalogowanej osoby do jednego przepisu („dolane 50 ml”). Dopisek nie jest
 * wykonaniem: nie tworzy `cooked_events`, powiadomienia ani oznaczenia
 * „Ugotowałem”. Do formularza „Ugotowałem” trafia tylko wtedy, gdy osoba
 * świadomie o to poprosi, i nawet wtedy trzeba go jeszcze wysłać.
 *
 * IZOLACJA PRÓB: jeden dopisek na osobę i przepis (`UNIQUE (user_id,
 * recipe_id)`), więc nie przechodzi na inny przepis ani na inne konto.
 * Następnego gotowania tego samego przepisu nie dotyczy, bo dopisek
 * wygasa po `kuking.cooking_note.retention_hours` (24 h od ostatniej zmiany)
 * i znika po zapisaniu wykonania tego przepisu.
 *
 * KONFLIKT DWÓCH URZĄDZEŃ: `revision` rośnie o 1 przy każdym zapisie; formularz
 * niesie rewizję, którą widział, a zapis ze starą rewizją jest odrzucany.
 *
 * PRYWATNOŚĆ: widoczne wyłącznie dla właściciela (`CookingNotePolicy`); jest
 * w paczce danych (`dopiski_z_gotowania`) i znika przy wymazaniu konta.
 *
 * ROLLBACK
 * `down()` usuwa tabelę. To tekst wpisany przez człowieka, więc `down()`
 * ODMAWIA, gdy jest choć jeden niewygasły wiersz (D-088: odmowa wąska — świeża
 * baza, CI i tabela z samymi wygasłymi wierszami przechodzą bez pytania).
 * Wymuszenie: `KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooking_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'recipe_id']);
            $table->index('expires_at');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE cooking_notes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE cooking_notes ADD CONSTRAINT cooking_notes_body_check CHECK (char_length(body) BETWEEN 1 AND 500)');
            DB::statement('ALTER TABLE cooking_notes ADD CONSTRAINT cooking_notes_revision_check CHECK (revision >= 1)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cooking_notes')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('cooking_notes');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('cooking_notes')->where('expires_at', '>', now())->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje prywatne dopiski z gotowania — bezpowrotnie.
            Liczba niewygasłych dopisków, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE cooking_notes_kopia AS SELECT * FROM cooking_notes;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu
                 gotowania naprawia się bez ruszania bazy, a dopisek i tak wygasa po 24 godzinach
                 (`php artisan kuking:sprzataj-postep-gotowania --wszystkie` skasuje go od razu);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA=1.

            Na świeżym środowisku albo przy samych wygasłych wierszach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
