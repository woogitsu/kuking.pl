<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opcjonalna, prywatna lista ostatnio oglądanych przepisów (#2553, V2;
 * decyzja właściciela z 2.10.2026, D-333).
 *
 * CO TU JEST
 * 1. Kolumna `users.ostatnio_ogladane_wlaczone_at` (timestamptz NULL) — jawne,
 *    świadome włączenie funkcji przez osobę. `NULL` = wyłączone i TO JEST
 *    STAN DOMYŚLNY każdego konta, także istniejących. Brak backfillu: nikt
 *    nie dostaje włączonej funkcji po cichu, a historii nie odtwarzamy z logów.
 * 2. Tabela `recent_recipe_views`: jeden wiersz = „ta osoba, w tym momencie,
 *    ostatnio oglądała ten przepis”. Tylko identyfikator przepisu i czas
 *    ostatniej wizyty — ŻADNEJ kopii tytułu, zdjęcia, adresu z parametrami,
 *    wyszukiwanej frazy ani wyboru alergenów.
 *
 * CZEGO TU NIE MA. Liczników odwiedzin, powiązań z autorem, wpisu do
 * `cooked_events`, zeszytu, planera czy postępu gotowania. Lista nie służy
 * do rankingu, rekomendacji, feedu, statystyk ani powiadomień.
 *
 * `UNIQUE (user_id, recipe_id)`: powrót do tego samego przepisu przesuwa
 * JEDEN wiersz (`viewed_at`), nie dokłada drugiego. Limit pozycji i czas życia
 * (`kuking.ostatnio_ogladane.*`) pilnuje kod domenowy przy zapisie i przy
 * odczycie, a nocne `kuking:sprzataj-ostatnio-ogladane` zabiera resztę.
 *
 * `ON DELETE CASCADE` działa przy twardym usunięciu przepisu albo konta; konta
 * są anonimizowane (D-022), więc wymazanie kasuje wiersze jawnie
 * (`EraseAccountData`). Przepis usunięty miękko nie ujawnia niczego: każde
 * pokazanie listy pyta ponownie o widoczność (Policy `view`).
 *
 * `users` to istniejąca, gorąca tabela (AGENTS.md §6): kolumna bez wartości
 * domyślnej, bez klucza obcego i bez indeksu — zmiana samego katalogu,
 * bez przepisywania wierszy.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w tabeli są zapisane wizyty. To dane
 * o zachowaniu ludzi, których `up()` nie odtworzy. Odmowa jest wąska: na pustej
 * tabeli (CI, świeża baza, funkcja nikomu niewłączona) rollback przechodzi.
 * Zdjęcie kolumny z włączeniem jest bezpieczne w stronę prywatności (po
 * cofnięciu nic się nie zapisuje), więc samo nie blokuje. Wymuszenie po kopii
 * tabeli: `KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE=1`; szybsza droga bez
 * cofania schematu: `php artisan kuking:sprzataj-ostatnio-ogladane --wszystkie`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_recipe_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->timestampTz('viewed_at');

            $table->unique(['user_id', 'recipe_id']);
            $table->index(['user_id', 'viewed_at']);
            $table->index('recipe_id');
            $table->index('viewed_at');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE recent_recipe_views ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS ostatnio_ogladane_wlaczone_at timestamptz NULL');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestampTz('ostatnio_ogladane_wlaczone_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recent_recipe_views')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('recent_recipe_views');

        if (Schema::hasColumn('users', 'ostatnio_ogladane_wlaczone_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('ostatnio_ogladane_wlaczone_at');
            });
        }
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('recent_recipe_views')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje listy ostatnio oglądanych przepisów — bezpowrotnie.
            Liczba zapisanych wizyt, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE recent_recipe_views_kopia AS SELECT * FROM recent_recipe_views;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu naprawia się
                 bez ruszania bazy, a wizyty i tak wygasają po kilku dniach
                 (`php artisan kuking:sprzataj-ostatnio-ogladane --wszystkie` skasuje je od razu);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE=1.

            Na świeżym środowisku albo przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
