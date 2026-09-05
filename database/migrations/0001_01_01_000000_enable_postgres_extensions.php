<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rozszerzenia PostgreSQL, na których stoi cała reszta schematu.
 *
 * - pgcrypto  → gen_random_uuid() jako domyślna wartość kluczy głównych,
 *               dzięki czemu poprawny wiersz da się wstawić także surowym SQL-em
 *               (seedy, importy, naprawy w psql), nie tylko przez Eloquent.
 * - pg_trgm   → indeksy GIN dla wyszukiwania "podobnych" fraz (nazwy użytkowników,
 *               tytuły przepisów, składniki). To jest cała wyszukiwarka MVP —
 *               bez Typesense i bez Meilisearch (docs/ARCHITECTURE.md).
 * - unaccent  → normalizacja polskich znaków diakrytycznych w wyszukiwaniu
 *               ("zurek" ma znajdować "żurek").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        // Rozszerzeń celowo nie usuwamy — mogą być używane przez inne obiekty
        // w bazie, a ich zdjęcie nie jest bezpieczną operacją odwracalną.
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
