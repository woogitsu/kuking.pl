<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Ugotujmy razem” (F3) — przepis tygodnia wybrany przez gospodarza.
 *
 * Jeden wiersz to jeden tydzień ISO i jeden przepis. Tydzień zapisujemy jako
 * jego PONIEDZIAŁEK (`week_starts_on`, `date`), liczony w strefie
 * `kuking.strefa` (Europe/Warsaw), nie w UTC — „ten tydzień” jest decyzją
 * o dniach w polskim kalendarzu, a nie o momentach. Wykonania z tygodnia
 * wybiera się potem oknem [poniedziałek 00:00, następny poniedziałek 00:00)
 * w tej samej strefie (`App\Domain\UgotujmyRazem\TydzienGotowania`).
 *
 * JEDEN PRZEPIS NA TYDZIEŃ JEST W BAZIE, NIE W PHP: `UNIQUE (week_starts_on)`
 * i `CHECK` na poniedziałek — dwóch gospodarzy zapisujących naraz nie zrobi
 * dwóch „przepisów tygodnia”, a data środka tygodnia nie przejdzie.
 *
 * `recipe_id ON DELETE CASCADE`: przepisy kasujemy miękko, więc twarde
 * usunięcie zdarza się przy wymazaniu konta autora — wtedy wybór tygodnia
 * nie ma już czego pokazać i znika razem z przepisem (archiwum i tak by go
 * nie pokazało). `chosen_by ON DELETE SET NULL`: wymazanie konta gospodarza
 * nie kasuje archiwum wspólnego gotowania.
 *
 * ROLLBACK (D-088, AGENTS.md §6): `down()` kasuje tabelę razem z archiwum
 * tygodni, czyli z decyzjami gospodarza, których `up()` nie odtworzy. Dlatego
 * ODMAWIA, gdy w tabeli są wiersze — na świeżej i pustej bazie (CI,
 * `migrate:refresh`) przechodzi bez pytania. Świadome przejście:
 * `KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM=1` po zrobieniu kopii tabeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_recipe_picks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('week_starts_on')->unique();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('chosen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // Klucz obcy bez indeksu to pełny skan przy kasowaniu przepisu i konta.
            $table->index('recipe_id');
            $table->index('chosen_by');
        });

        DB::statement('ALTER TABLE weekly_recipe_picks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE weekly_recipe_picks ADD CONSTRAINT weekly_recipe_picks_poniedzialek_check
            CHECK (EXTRACT(ISODOW FROM week_starts_on) = 1)');
    }

    public function down(): void
    {
        if (Schema::hasTable('weekly_recipe_picks')
            && getenv('KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM') !== '1') {
            $ile = DB::table('weekly_recipe_picks')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Cofnięcie tej migracji skasowałoby archiwum „Ugotujmy razem” (weekly_recipe_picks nie jest pusta). '
                    ."Liczba tygodni, które znikną: {$ile}. "
                    .'Jeśli naprawdę trzeba: zrób kopię tabeli (pg_dump -t weekly_recipe_picks) '
                    .'i uruchom rollback z KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM=1.',
                );
            }
        }

        Schema::dropIfExists('weekly_recipe_picks');
    }
};
