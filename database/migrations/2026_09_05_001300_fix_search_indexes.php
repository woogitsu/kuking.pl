<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Naprawa indeksów wyszukiwarki.
 *
 * PROBLEM
 * Pierwotne indeksy stały na SUROWYCH kolumnach:
 *     CREATE INDEX ... ON recipes USING gin (title gin_trgm_ops)
 * a zapytanie pytało o wyrażenie:
 *     WHERE unaccent(lower(title)) % 'zurek'
 *
 * PostgreSQL używa indeksu na wyrażeniu tylko wtedy, gdy zapytanie zawiera
 * DOKŁADNIE to samo wyrażenie. Sprawdzone: nawet z `enable_seqscan = off`
 * planer wybierał Seq Scan, bo indeks był po prostu nieużywalny.
 * Każde wyszukiwanie skanowało całą tabelę.
 *
 * ROZWIĄZANIE
 * Indeksy na tym samym wyrażeniu, którego używa App\Domain\Search\SearchQuery.
 *
 * Jest tu jedna pułapka: `unaccent()` NIE jest funkcją IMMUTABLE (zależy od
 * słownika, który teoretycznie można podmienić), a PostgreSQL nie pozwala
 * indeksować wyrażeń nieimmutable. Dlatego opakowujemy ją we własną funkcję
 * `kuking_normalize()` z jawnie wskazanym słownikiem `'unaccent'` —
 * to jest udokumentowane podejście z dokumentacji PostgreSQL.
 *
 * Konsekwencja, o której trzeba wiedzieć: gdyby ktoś kiedyś podmienił słownik
 * unaccent, indeksy trzeba przebudować (REINDEX). Nie robimy tego, więc
 * kompromis jest bezpieczny.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // Funkcja normalizująca — jedno miejsce, z którego korzystają
        // i indeksy, i zapytania.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION kuking_normalize(text)
            RETURNS text
            AS $$ SELECT unaccent('unaccent', lower($1)) $$
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
        SQL);

        // Stare, nieużywane indeksy.
        foreach ([
            'profiles_username_trgm_idx',
            'profiles_display_name_trgm_idx',
            'recipes_title_trgm_idx',
            'ingredients_name_trgm_idx',
            'recipe_ingredients_text_trgm_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        // Nowe — na dokładnie tym wyrażeniu, o które pyta wyszukiwarka.
        DB::statement('CREATE INDEX profiles_username_trgm_idx ON profiles USING gin (kuking_normalize(username) gin_trgm_ops)');
        DB::statement('CREATE INDEX profiles_display_name_trgm_idx ON profiles USING gin (kuking_normalize(display_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX profiles_speciality_trgm_idx ON profiles USING gin (kuking_normalize(coalesce(speciality, \'\')) gin_trgm_ops)');
        DB::statement('CREATE INDEX recipes_title_trgm_idx ON recipes USING gin (kuking_normalize(title) gin_trgm_ops)');
        DB::statement('CREATE INDEX recipes_summary_trgm_idx ON recipes USING gin (kuking_normalize(coalesce(summary, \'\')) gin_trgm_ops)');
        DB::statement('CREATE INDEX ingredients_name_trgm_idx ON ingredients USING gin (kuking_normalize(normalized_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX recipe_ingredients_text_trgm_idx ON recipe_ingredients USING gin (kuking_normalize(ingredient_text) gin_trgm_ops)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach ([
            'profiles_username_trgm_idx',
            'profiles_display_name_trgm_idx',
            'profiles_speciality_trgm_idx',
            'recipes_title_trgm_idx',
            'recipes_summary_trgm_idx',
            'ingredients_name_trgm_idx',
            'recipe_ingredients_text_trgm_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement('DROP FUNCTION IF EXISTS kuking_normalize(text)');

        // Przywracamy stan sprzed migracji — indeksy na surowych kolumnach.
        // Były nieużywane, ale down() ma odtwarzać poprzedni stan, nie ulepszać go.
        DB::statement('CREATE INDEX profiles_username_trgm_idx ON profiles USING gin (username gin_trgm_ops)');
        DB::statement('CREATE INDEX profiles_display_name_trgm_idx ON profiles USING gin (display_name gin_trgm_ops)');
        DB::statement('CREATE INDEX recipes_title_trgm_idx ON recipes USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX ingredients_name_trgm_idx ON ingredients USING gin (normalized_name gin_trgm_ops)');
        DB::statement('CREATE INDEX recipe_ingredients_text_trgm_idx ON recipe_ingredients USING gin (ingredient_text gin_trgm_ops)');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
