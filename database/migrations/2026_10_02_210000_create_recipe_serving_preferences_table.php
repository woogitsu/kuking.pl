<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jawnie zapamiętana własna liczba porcji dla jednego przepisu (#2602, V2).
 *
 * CO TU JEST
 * Tabela `recipe_serving_preferences`: jeden wiersz = „ta osoba świadomie
 * zapisała, że ten przepis zwykle robi na tyle porcji”. Wiersz powstaje
 * wyłącznie po naciśnięciu przycisku „Zapamiętaj dla mnie” i znika po
 * „Zapomnij moje ustawienie”. Nic nie zapisuje się samo: ani przy kliknięciu
 * „Mniej”/„Więcej”, ani z adresu `?porcje=`, ani z postępu gotowania, planera
 * czy wykonań. Brak backfillu.
 *
 * CZEGO TU NIE MA. Nie zapisujemy przeliczonych składników (receptura
 * zostaje autora — D-284), ani liczby domowników, ani nic, co mówi o rodzinie.
 * Tylko liczba, jedna na parę (osoba, przepis). Nie wpływa na feed, rankingi
 * ani powiadomienia.
 *
 * `UNIQUE (user_id, recipe_id)` — stan, nie zdarzenie: zmiana liczby to
 * nadpisanie tego samego wiersza. `CHECK` 1–100 jak w `WyborPorcji`.
 * Limit liczby zapisów na osobę (`kuking.porcje_zapamietane.limit_na_osobe`, 500) pilnuje
 * akcja domenowa, bo CHECK nie liczy wierszy.
 *
 * `ON DELETE CASCADE` działa przy twardym usunięciu przepisu albo konta;
 * konta są anonimizowane (D-022), więc wymazanie kasuje wiersze jawnie
 * (`EraseAccountData`). Przepis usunięty miękko nie daje dostępu do niczego:
 * odczyt zawsze idzie najpierw przez `RecipePolicy::view`.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w tabeli są zapisane wybory. To
 * świadome decyzje ludzi, których `up()` nie odtworzy. Odmowa jest wąska:
 * na pustej tabeli (CI, świeża baza) rollback przechodzi. Wymuszenie po
 * kopii tabeli: `KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_serving_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->decimal('servings', 6, 2);
            $table->timestampsTz();

            $table->unique(['user_id', 'recipe_id']);
            $table->index('recipe_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_serving_preferences ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement(
                'ALTER TABLE recipe_serving_preferences ADD CONSTRAINT recipe_serving_preferences_servings_check '
                .'CHECK (servings >= 1 AND servings <= 100)',
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_serving_preferences')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('recipe_serving_preferences');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('recipe_serving_preferences')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje zapamiętane przez ludzi liczby porcji przy przepisach — bezpowrotnie.
            Liczba zapisanych wyborów, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE recipe_serving_preferences_kopia AS SELECT * FROM recipe_serving_preferences;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu przepisu naprawia się
                 bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW=1.

            Na świeżym środowisku albo przy pustej tabeli cofnięcie działa bez pytania.
            TEKST);
    }
};
