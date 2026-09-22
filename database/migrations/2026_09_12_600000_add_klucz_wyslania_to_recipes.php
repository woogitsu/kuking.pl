<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klucz wysłania dla przepisu: jedno wysłanie formularza to jeden przepis.
 *
 * Ten sam mechanizm i ta sama decyzja co przy wpisie i przy „Ugotowałem"
 * (D-027, ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §4, wariant A3).
 * Przepis został w audycie z 7 września 2026 pominięty — mierzono wtedy
 * `PublishPost`, `RecordCookedEvent` i `ReportContent`, a `PublishRecipe`
 * nie był w zadaniu.
 *
 * CO BYŁO ZMIERZONE (audyt podwójnego wysłania, 12 września 2026)
 * Dwa razy `POST /dodaj/przepis` z identycznym ciałem dawało DWA wiersze
 * w `recipes`. Drugi dostawał własny slug od `GenerateRecipeSlug`, więc
 * człowiek lądował pod adresem `…/rosol-babci-zofii-2` — przepisu o tej
 * nazwie, o którego istnieniu nie wiedział. Razem z nim powstawał drugi
 * komplet `recipe_ingredients` i `recipe_steps`, druga wersja
 * w `recipe_versions`, drugi wpis `recipe.published` w dzienniku audytowym
 * i DRUGI WPIS W STRUMIENIU obserwujących (`WpisWskazujacyPrzepis`).
 *
 * DLACZEGO INDEKS JEST NA PARZE (author_id, klucz_wyslania)
 * Dokładnie jak w `posts` i `cooked_events`: klucz wygenerowany w cudzej
 * przeglądarce nie ma prawa wskazywać na przepis innej osoby. Przy kolizji
 * akcja domenowa oddaje przepis TEJ osoby, nigdy cudzy.
 *
 * DLACZEGO KOLUMNA MOŻE BYĆ `NULL` I DLACZEGO INDEKS JEST CZĘŚCIOWY
 * Bez backfillu i bez `NOT NULL`: przepisy istniejące, przepisy z fabryk
 * i z seederów zostają poza indeksem. `NOT NULL` rozwaliłoby
 * `database/seeders/` i każdy test tworzący przepis fabryką, a mechanizm ma
 * zawodzić OTWARCIE: brak klucza znaczy „zapisz normalnie", nigdy
 * „odmawiam" (ADR §4.3).
 *
 * KLUCZ DOTYCZY TYLKO ZAKŁADANIA PRZEPISU, NIE JEGO EDYCJI.
 * `recipes.update` pracuje na wierszu, który już istnieje, i nie przysyła
 * klucza — kolumna zostaje wtedy nietknięta. Gdyby edycja klucz nadpisywała,
 * pierwsze zapisanie szczegółów kasowałoby ochronę tego przepisu.
 *
 * DLACZEGO NAZWA KOLUMNY NIE ZAWIERA „token"
 * `OdzyskiwalneDane::jestWrazliwe()` dopasowuje po FRAGMENCIE nazwy, a lista
 * fragmentów zawiera `token` — pole nazwane `token_wyslania` zniknęłoby
 * z ekranu 419 (ADR §1.4.4). `recipes.store` jest na liście tras
 * odzyskiwalnych, więc dotyczy to także tego formularza.
 *
 * ROLLBACK: `DROP INDEX`, potem `DROP COLUMN`. Bezstratnie i dlatego `down()`
 * niczego nie odmawia (D-088 dotyczy wartości SEMANTYCZNYCH): kolumna niesie
 * wyłącznie identyfikator wysłania wygenerowany przez serwer, ani jednego
 * słowa napisanego przez człowieka i ani jednej decyzji, którą ktoś podjął.
 * Po cofnięciu wracają duplikaty, ale nie ginie ani jeden przepis.
 */
return new class extends Migration
{
    private const INDEKS = 'recipes_one_per_klucz_wyslania';

    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->uuid('klucz_wyslania')->nullable()->after('author_id');
        });

        if ($this->isPostgres()) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEKS.' ON recipes (author_id, klucz_wyslania) '
                .'WHERE klucz_wyslania IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('klucz_wyslania');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
