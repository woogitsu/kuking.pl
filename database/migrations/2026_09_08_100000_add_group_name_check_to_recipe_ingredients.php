<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grupy składników — „Ciasto", „Farsz", „Do podania" (D-033, część pierwsza).
 *
 * CO JUŻ BYŁO, A CZEGO NIE BYŁO
 * Kolumna `recipe_ingredients.group_name varchar(120) NULL` stoi w schemacie
 * od pierwszej migracji przepisów (`2026_09_05_000400_create_recipes_tables`)
 * i zapisuje ją zarówno kreator Livewire, jak i akcja `PublishRecipe`.
 * Brakowało trzech innych rzeczy: pola w formularzu BEZ JavaScriptu,
 * nagłówków grup na stronie przepisu i jednego zdania o tej kolumnie
 * w `docs/DATABASE.md`. Ta migracja NIE dokłada więc drugiej kolumny na to
 * samo — dokłada jedyne ograniczenie, którego tej kolumnie brakowało.
 *
 * WYBRANY KSZTAŁT: NAZWA GRUPY W WIERSZU SKŁADNIKA
 * Kolejność grup bierze się z `position` składników, a nie z osobnej liczby.
 * Autor pisze listę od góry do dołu i to jest cała informacja o kolejności,
 * jaką ma — drugie źródło kolejności to drugie miejsce, w którym może się
 * ona rozjechać z pierwszym.
 *
 * ODRZUCONE: OSOBNA TABELA `recipe_ingredient_groups (recipe_id, name, position)`
 * plus `recipe_ingredients.group_id`. Wygląda porządniej i rozwiązywałaby
 * literówki „farsz"/„Farsz" przez UNIQUE — ale:
 *   1. grupa nie ma własnego życia. Nikt nie zakłada „Farszu", żeby potem
 *      wkładać do niego składniki; ludzie piszą listę i po drodze nadają jej
 *      śródtytuły. Skasowanie ostatniego składnika grupy zostawiałoby pusty
 *      nagłówek, o którego istnieniu autor już nie wie;
 *   2. obie drogi zapisu kasują składniki i piszą je od nowa
 *      (`PublishRecipe::syncIngredients`). Każdy zapis przepisu stawałby się
 *      synchronizacją DWÓCH list zamiast jednej, a kolejność tych dwóch
 *      operacji byłaby kolejną rzeczą, którą da się pomylić;
 *   3. UNIQUE na nazwie działa i tak wyłącznie W OBRĘBIE jednego przepisu —
 *      czyli daje dokładnie tyle, co ujednolicenie nazw przy zapisie
 *      (`PublishRecipe`), za cenę klucza obcego i JOIN-a na najczęściej
 *      czytanej stronie serwisu, w kolumnie, którą większość przepisów
 *      zostawia pustą.
 *
 * ODRZUCONE: SŁOWNIK NAZW GRUP WSPÓLNY DLA CAŁEGO SERWISU. Zamienia
 * śródtytuł napisany przez człowieka w taksonomię, którą ktoś musi
 * pielęgnować — a „Do podania" u jednej osoby i „Do podania" u drugiej nie
 * znaczą tego samego.
 *
 * ODRZUCONE: KOLUMNA `group_position` przy składniku. Kolejność grup już
 * wynika z kolejności wierszy; osobna liczba pozwala ustawić je sprzecznie
 * i wtedy nikt nie wie, która wygrywa.
 *
 * ODRZUCONE: NAGŁÓWEK JAKO WIERSZ SKŁADNIKA z flagą `is_header` (tak robi to
 * Tandoor — `docs/research/repos/TandoorRecipes-recipes.md` §2.2). Nagłówek
 * zajmowałby wtedy pozycję na liście składników, więc trafiałby do
 * `recipeIngredient` w structured data i do eksportu jako składnik, którego
 * nie ma w żadnej kuchni.
 *
 * CO TEN CHECK PILNUJE
 * Że nazwa grupy, jeśli już jest, ma widoczny tekst. Pusty ciąg znaków to
 * nagłówek bez treści: na ekranie pusta linia, a w czytniku ekranu „nagłówek
 * poziomu trzeciego" i cisza. Sama walidacja w PHP nie wystarczy — wiersze
 * wchodzą też z fabryki, z konsoli i z przyszłego importu (AGENTS.md §6).
 *
 * CZEGO TEN CHECK NIE PILNUJE
 * CIĄGŁOŚCI GRUP. Da się zapisać „poz. 0 Ciasto, poz. 1 Farsz, poz. 2 Ciasto",
 * bo CHECK nie widzi sąsiednich wierszy, a wyrażenie EXCLUDE dla reguły
 * „wiersze jednej grupy leżą obok siebie" byłoby na to o kilka rzędów za
 * dużą maszyną. To jest rzecz do testu i do widoku, nie do migracji —
 * dokładnie tak, jak zapisano w `docs/research/repos/TandoorRecipes-recipes.md`
 * §2.2 (rekomendacja R8). Widok scala wiersze tej samej grupy pod JEDNYM
 * nagłówkiem (`App\Domain\Recipes\GrupySkladnikow`), więc przeplot nie robi
 * z jednej grupy dwóch.
 *
 * ROLLBACK
 * `down()` zdejmuje sam CHECK i nie rusza żadnych danych. Nieodwracalna jest
 * jedna rzecz z `up()`: nazwy grup będące pustym ciągiem znaków zostają
 * zamienione na NULL. To nie jest utrata informacji — pusty ciąg nigdy nie
 * był nazwą grupy, był nagłówkiem bez treści. Kolumny ta migracja nie
 * dokłada i nie kasuje, więc cofnięcie nie może zabrać ani jednej nazwy
 * grupy napisanej przez człowieka.
 */
return new class extends Migration
{
    private const CHECK = 'recipe_ingredients_group_name_check';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // Najpierw dane, potem ograniczenie — inaczej migracja wywraca się na
        // wierszu zapisanym wtedy, gdy reguły jeszcze nie było.
        DB::statement(<<<'SQL'
            UPDATE recipe_ingredients
            SET group_name = NULL
            WHERE group_name IS NOT NULL AND btrim(group_name) = ''
        SQL);

        // `DROP ... IF EXISTS` przed dodaniem, żeby ponowne puszczenie
        // migracji na bazie, która ten CHECK już ma, nie wywracało się na
        // „constraint already exists" (wzór z `IdempotencjaMigracjiTest`).
        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS '.self::CHECK);

        DB::statement(<<<'SQL'
            ALTER TABLE recipe_ingredients
            ADD CONSTRAINT recipe_ingredients_group_name_check
            CHECK (group_name IS NULL OR btrim(group_name) <> '')
        SQL);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS '.self::CHECK);
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
