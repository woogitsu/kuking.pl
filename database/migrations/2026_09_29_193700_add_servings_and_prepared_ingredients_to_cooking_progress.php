<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronizacja gotowania, etap 2 (#2016): wybrana liczba porcji i składniki
 * „przygotowane” (#2069) w tym samym wierszu `cooking_progress`.
 *
 * Wiersz nadal wybiera para (osoba, przepis) i nadal jest zgodą — kto nie
 * włączył synchronizacji dla przepisu, nie ma tych danych na koncie: porcje
 * zostają w adresie (`?porcje=`), a składniki w `sessionStorage` przeglądarki.
 *
 * - `servings` — `numeric(6,2)`, NULL = „porcje z przepisu” (nic nie wybrano).
 *   CHECK 1–100 jak `WyborPorcji::NAJMNIEJ/NAJWIECEJ`.
 * - `prepared_ingredient_ids` — `jsonb`, tablica ID składników (nie pozycji),
 *   sufit 300; ID składników, których przepis już nie ma, są odrzucane przy
 *   odczycie i wypadają przy zapisie.
 *
 * Retencja i rewizja są wspólne z krokami (`expires_at`, `revision`).
 * Minutników świadomie nie ma — patrz `docs/DATABASE.md`.
 *
 * ROLLBACK: `down()` usuwa obie kolumny. ODMAWIA (D-088), gdy w niewygasłym
 * wierszu jest wybrana liczba porcji albo choć jeden przygotowany składnik.
 * Wymuszenie: `KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA=1`.
 */
return new class extends Migration
{
    // NOT VALID + VALIDATE muszą być osobnymi transakcjami (AGENTS.md §6).
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('cooking_progress', function (Blueprint $table): void {
            $table->decimal('servings', 6, 2)->nullable();
            $table->jsonb('prepared_ingredient_ids')->default('[]');
        });

        if ($this->isPostgres()) {
            // Kolumny są nowe (NULL i '[]'), więc walidacja nie znajdzie
            // naruszeń; i tak idziemy wzorcem NOT VALID + VALIDATE.
            DB::statement('ALTER TABLE cooking_progress ADD CONSTRAINT cooking_progress_servings_check CHECK (servings IS NULL OR (servings >= 1 AND servings <= 100)) NOT VALID');
            DB::statement(
                'ALTER TABLE cooking_progress ADD CONSTRAINT cooking_progress_prepared_check '
                ."CHECK (jsonb_typeof(prepared_ingredient_ids) = 'array' AND jsonb_array_length(prepared_ingredient_ids) <= 300) NOT VALID",
            );
            DB::statement('ALTER TABLE cooking_progress VALIDATE CONSTRAINT cooking_progress_servings_check');
            DB::statement('ALTER TABLE cooking_progress VALIDATE CONSTRAINT cooking_progress_prepared_check');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cooking_progress', 'servings')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE cooking_progress DROP CONSTRAINT IF EXISTS cooking_progress_servings_check');
            DB::statement('ALTER TABLE cooking_progress DROP CONSTRAINT IF EXISTS cooking_progress_prepared_check');
        }

        Schema::table('cooking_progress', function (Blueprint $table): void {
            $table->dropColumn(['servings', 'prepared_ingredient_ids']);
        });
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('cooking_progress')
            ->where('expires_at', '>', now())
            ->where(function ($q): void {
                $q->whereNotNull('servings')->orWhereRaw("prepared_ingredient_ids <> '[]'::jsonb");
            })
            ->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje zapamiętane na koncie porcje i składniki „przygotowane” — bezpowrotnie.
            Liczba niewygasłych postępów, którym zniknie ta część: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE cooking_progress_kopia AS SELECT * FROM cooking_progress;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu gotowania
                 naprawia się bez ruszania bazy, a postęp i tak wygasa po 24 godzinach
                 (`php artisan kuking:sprzataj-postep-gotowania --wszystkie` skasuje go od razu);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA=1.

            Na świeżym środowisku albo przy samych wygasłych wierszach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
