<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ALERGENY PRZEPISU — oznaczenie autora (issue #1902, decyzja właściciela
 * z 30.09.2026, D-333).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Autor może zaznaczyć, które z 14 alergenów z Załącznika II rozporządzenia
 * 1169/2011 są w przepisie, i potwierdzić, że lista jest pełna. Czytelnik
 * widzi to zaznaczenie „według autora” albo „Alergeny: nie sprawdzono”,
 * a filtr w wyszukiwarce przepuszcza wyłącznie przepisy ze stanem `declared`
 * (fail-closed). Całość za flagą `KUKING_ALERGENY_WLACZONE` (domyślnie
 * wyłączona) — kolumny są w schemacie niezależnie od flagi.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KOLUMNY (poziom przepisu, nie składnika — `PublishRecipe::syncIngredients()`
 *  kasuje i zakłada wiersze składników przy każdym zapisie)
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - `allergen_status varchar(16) NOT NULL DEFAULT 'unchecked'`:
 *    `unchecked` (autor nic nie zaznaczył; także każdy istniejący przepis),
 *    `declared` (autor zaznaczył i potwierdził pełną listę — może być pusta),
 *    `needs_review` (po potwierdzeniu zmieniono składniki).
 *  - `allergens text[] NOT NULL DEFAULT '{}'` — kody z zamkniętej listy.
 *  - `allergens_declared_at timestamptz NULL` — kiedy autor potwierdził.
 *
 * `ADD COLUMN` ze stałą wartością domyślną nie przepisuje tabeli (PG 11+).
 * CHECK-i idą jako `NOT VALID`, potem osobno `VALIDATE`, poza transakcją
 * (AGENTS.md §6). Wszystkie istniejące wiersze mają stan domyślny, więc
 * walidacja przechodzi.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — ODMAWIA, gdy choć jeden przepis ma deklarację (D-088)
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` + kolejny `migrate` wróciłby ze stanem domyślnym „nie sprawdzono”,
 * czyli po cichu skasowałby decyzję autora o alergenach. Odmowa jest wąska:
 * gdy wszystkie przepisy są `unchecked` (świeża baza, CI), cofnięcie
 * przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const STATUS_CHECK = 'recipes_allergen_status_check';

    private const LISTA_CHECK = 'recipes_allergens_closed_list_check';

    private const TYLKO_PO_DEKLARACJI_CHECK = 'recipes_allergens_only_when_declared_check';

    private const DATA_CHECK = 'recipes_allergens_declared_at_check';

    /** Ta sama lista, co `App\Domain\Recipes\Alergeny\Alergen::kody()` — pilnuje tego test. */
    private const KODY = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('recipes', 'allergen_status')) {
            DB::statement("ALTER TABLE recipes ADD COLUMN allergen_status varchar(16) NOT NULL DEFAULT 'unchecked'");
        }
        if (! Schema::hasColumn('recipes', 'allergens')) {
            DB::statement("ALTER TABLE recipes ADD COLUMN allergens text[] NOT NULL DEFAULT '{}'");
        }
        if (! Schema::hasColumn('recipes', 'allergens_declared_at')) {
            DB::statement('ALTER TABLE recipes ADD COLUMN allergens_declared_at timestamptz NULL');
        }

        $lista = "ARRAY['".implode("','", self::KODY)."']::text[]";

        $ograniczenia = [
            self::STATUS_CHECK => "allergen_status IN ('unchecked','declared','needs_review')",
            self::LISTA_CHECK => "allergens <@ {$lista}",
            self::TYLKO_PO_DEKLARACJI_CHECK => "allergen_status <> 'unchecked' OR cardinality(allergens) = 0",
            self::DATA_CHECK => "(allergen_status = 'unchecked') = (allergens_declared_at IS NULL)",
        ];

        foreach ($ograniczenia as $nazwa => $warunek) {
            DB::statement("ALTER TABLE recipes DROP CONSTRAINT IF EXISTS {$nazwa}");
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT {$nazwa} CHECK ({$warunek}) NOT VALID");
        }

        foreach (array_keys($ograniczenia) as $nazwa) {
            try {
                DB::statement("ALTER TABLE recipes VALIDATE CONSTRAINT {$nazwa}");
            } catch (Throwable $e) {
                foreach (array_keys($ograniczenia) as $doZdjecia) {
                    DB::statement("ALTER TABLE recipes DROP CONSTRAINT IF EXISTS {$doZdjecia}");
                }

                throw new RuntimeException(
                    "Nie udało się zwalidować {$nazwa}: w tabeli recipes jest wiersz z niespójnym oznaczeniem "
                    .'alergenów. Ograniczenia zostały zdjęte. Popraw te wiersze (zapytanie w docs/DATABASE.md, '
                    .'sekcja recipes) i uruchom migrację ponownie.',
                    previous: $e,
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipes', 'allergen_status')) {
            return;
        }

        // Sprawdzenie i DDL pod jedną blokadą: deklaracja zapisana między
        // policzeniem a `DROP COLUMN` zniknęłaby bez śladu.
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE recipes IN ACCESS EXCLUSIVE MODE');

            $zdeklarowanych = (int) DB::table('recipes')->where('allergen_status', '<>', 'unchecked')->count();

            if ($zdeklarowanych > 0 && ! $this->wolnoSkasowac()) {
                throw new RuntimeException(
                    'Cofnięcie tej migracji skasowałoby oznaczenia alergenów, które autorzy wpisali w przepisach (D-088). '
                    .'Liczba przepisów z oznaczeniem: '.$zdeklarowanych.".\n\n"
                    ."CZYM TO GROZI\n"
                    .'Po ponownym `migrate` kolumny wrócą ze stanem „nie sprawdzono”, a zaznaczenia autorów '
                    ."znikną bez jednego komunikatu.\n\n"
                    ."CO ZROBIĆ ZAMIAST TEGO\n"
                    ."Wycofaj sam kod, zostawiając kolumny — stary kod ich nie czyta. Albo zapisz dane:\n"
                    ."  \\copy (SELECT id, allergen_status, allergens, allergens_declared_at FROM recipes WHERE allergen_status <> 'unchecked') \n"
                    ."  TO 'alergeny.csv' CSV HEADER\n\n"
                    ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                    .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_ALERGENY=true php artisan migrate:rollback',
                );
            }

            foreach ([self::DATA_CHECK, self::TYLKO_PO_DEKLARACJI_CHECK, self::LISTA_CHECK, self::STATUS_CHECK] as $nazwa) {
                DB::statement("ALTER TABLE recipes DROP CONSTRAINT IF EXISTS {$nazwa}");
            }

            DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS allergens_declared_at');
            DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS allergens');
            DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS allergen_status');
        });
    }

    /** `getenv()`, nie `env()` — przy zbuforowanej konfiguracji `env()` oddaje `null`. */
    private function wolnoSkasowac(): bool
    {
        return filter_var((string) getenv('KUKING_ROLLBACK_KASUJ_ALERGENY'), FILTER_VALIDATE_BOOLEAN);
    }
};
