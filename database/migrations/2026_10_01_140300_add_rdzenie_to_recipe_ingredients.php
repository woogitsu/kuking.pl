<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ZAPISANE RDZENIE LINIJKI SKŁADNIKA (audyt wydajności: „Co ugotuję z tego, co mam").
 *
 * CO BYŁO ŹLE
 * `App\Domain\Pantry\CoUgotuje` porównuje produkty z listy człowieka
 * z linijkami przepisów przez `p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text)`.
 * Funkcja (rozbicie tekstu, normalizacja, słownik form) była liczona od nowa
 * dla każdej linijki każdego kandydata — i to w warunku złączenia, czyli po
 * razy liczbę produktów. Przy 500 przepisach × 7 składnikach i 20 produktach
 * to ok. 1–2,2 s na jedno wejście na ekran.
 *
 * CO ROBI
 * Trzyma wynik funkcji w `recipe_ingredients.rdzenie text[]` i buduje na nim
 * indeks GIN (`recipe_ingredients_rdzenie_gin_idx`). Reguła rdzeni nadal
 * mieszka WYŁĄCZNIE w bazie (funkcja); kolumnę wypełnia wyzwalacz BEFORE
 * INSERT OR UPDATE OF ingredient_text, więc żaden kod aplikacji — ani
 * `PublishRecipe`, ani seeder, ani `INSERT … SELECT` — niczego nie pamięta.
 *
 * DLACZEGO NIE `GENERATED … STORED`
 * `ADD COLUMN … GENERATED ALWAYS AS (…) STORED` przepisuje całą tabelę pod
 * `ACCESS EXCLUSIVE` (AGENTS.md §6 każe tego unikać na gorących tabelach;
 * migracja `2026_09_09_100000` tak zrobiła, bo produkcja była wtedy mała).
 * Tu wybieramy wariant bez przepisywania:
 *   1. `ADD COLUMN rdzenie text[]` bez DEFAULT — sama zmiana katalogu,
 *      blokada wyłączna na milisekundy (limit: `lock_timeout = 5 s`);
 *   2. wyzwalacz — od tej chwili każdy nowy i zmieniony wiersz ma rdzenie;
 *   3. backfill partiami po 2000 wierszy, każda partia w OSOBNEJ transakcji
 *      (migracja ma `$withinTransaction = false`): blokady tylko na wierszach
 *      partii, żadnej długiej transakcji, VACUUM zdąży sprzątać w trakcie;
 *   4. `CHECK (rdzenie IS NOT NULL) NOT VALID` + `VALIDATE CONSTRAINT`
 *      (`SHARE UPDATE EXCLUSIVE` — zapisy idą dalej);
 *   5. `CREATE INDEX CONCURRENTLY` — bez blokady zapisów.
 *
 * KOSZT NA PRODUKCJI
 * Backfill przepisuje każdy wiersz raz (nowa krotka + martwa stara): rozmiar
 * tabeli rośnie chwilowo do ok. 2×, potem VACUUM odzyskuje miejsce; WAL ok.
 * 1–2× rozmiaru tabeli. Czas rośnie liniowo: na bazie pomiarowej (80 tys.
 * linijek) kilka sekund. Migracja jest wznawialna — przerwana w backfillu
 * po prostu wypełnia resztę przy kolejnym uruchomieniu (`WHERE rdzenie IS NULL`).
 *
 * ⚠️ UWAGA NA PRZYSZŁE ZMIANY FUNKCJI
 * Kolumna jest ZWYKŁA, nie generowana, więc zmiana ciała
 * `kuking_rdzenie_skladnika()` jej NIE przelicza (ten sam mechanizm, co przy
 * `pantry_items` w #2315 — tam przelicza `UPDATE … SET name = name`). Migracja
 * zmieniająca funkcję musi przeliczyć też tę tabelę (partiami: `UPDATE
 * recipe_ingredients SET ingredient_text = ingredient_text WHERE …`).
 * Rozjazd wyłapuje `CoUgotujeKosztTest::test_zapisane_rdzenie_rowna_sie_funkcji_na_zywo`.
 *
 * ROLLBACK
 * `down()` zdejmuje indeks, CHECK, wyzwalacz, jego funkcję i kolumnę
 * (`DROP COLUMN` to zmiana katalogu, bez przepisywania). Kolumna to DANE
 * POCHODNE — w całości wyliczalne z `ingredient_text`, który zostaje — więc
 * nie ma tu wartości semantycznej ani decyzji człowieka, których D-088
 * kazałby bronić odmową; rollback jest bezstratny i nie odmawia. Po nim
 * `CoUgotuje` z tej wersji kodu nie zadziała (zapytanie czyta kolumnę) —
 * wycofanie wdrożenia cofa kod razem z migracją.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARTIA = 2000;

    private const INDEKS = 'recipe_ingredients_rdzenie_gin_idx';

    private const CHECK = 'recipe_ingredients_rdzenie_not_null_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Kolumna: bez DEFAULT i bez przepisywania tabeli.
        DB::statement('ALTER TABLE recipe_ingredients ADD COLUMN IF NOT EXISTS rdzenie text[]');

        // 2. Wyzwalacz: od teraz nowe i zmieniane wiersze liczą się same.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.kuking_recipe_ingredients_rdzenie() RETURNS trigger
            AS $$
            BEGIN
                NEW.rdzenie := public.kuking_rdzenie_skladnika(NEW.ingredient_text);
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS recipe_ingredients_rdzenie_trg ON recipe_ingredients');
        DB::statement(<<<'SQL'
            CREATE TRIGGER recipe_ingredients_rdzenie_trg
            BEFORE INSERT OR UPDATE OF ingredient_text ON recipe_ingredients
            FOR EACH ROW EXECUTE FUNCTION public.kuking_recipe_ingredients_rdzenie()
            SQL);

        // 3. Backfill partiami (każda w osobnej transakcji — migracja jest
        //    poza transakcją). Wiersze bez rdzeni znikają z warunku po partii.
        do {
            $zmienione = DB::affectingStatement(
                'UPDATE recipe_ingredients SET rdzenie = public.kuking_rdzenie_skladnika(ingredient_text) '
                .'WHERE id IN (SELECT id FROM recipe_ingredients WHERE rdzenie IS NULL LIMIT '.self::PARTIA.')',
            );
        } while ($zmienione > 0);

        // 4. Niezmiennik: NOT VALID (krótka blokada) → VALIDATE (bez blokady zapisów).
        $jest = DB::selectOne(
            'SELECT 1 AS jest FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [self::CHECK, 'recipe_ingredients'],
        ) !== null;
        if (! $jest) {
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT '.self::CHECK.' CHECK (rdzenie IS NOT NULL) NOT VALID');
        }
        DB::statement('ALTER TABLE recipe_ingredients VALIDATE CONSTRAINT '.self::CHECK);

        // 5. Indeks bez blokady zapisów; przerwana budowa zostawia INVALID,
        //    którego samo `IF NOT EXISTS` by nie ruszyło.
        $wspolbieznie = $this->wspolbieznie();
        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }
        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS.' ON recipe_ingredients USING gin (rdzenie)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX '.$this->wspolbieznie().'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('DROP TRIGGER IF EXISTS recipe_ingredients_rdzenie_trg ON recipe_ingredients');
        DB::statement('DROP FUNCTION IF EXISTS public.kuking_recipe_ingredients_rdzenie()');
        DB::statement('ALTER TABLE recipe_ingredients DROP COLUMN IF EXISTS rdzenie');
    }

    private function wspolbieznie(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }

    private function jestNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};
