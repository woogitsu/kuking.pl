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
    /**
     * ŚWIADOMIE PUSTY `down()` — deklaracja, nie przeoczenie.
     *
     * Ta stała nie jest ozdobą: czyta ją
     * `tests/Feature/KazdaMigracjaMaWycofanieTest.php`, który każdy inny pusty
     * `down()` w repozytorium oblewa. Dzięki temu „nie ma czego cofać" trzeba
     * NAPISAĆ, a nie tylko pomyśleć — a nowa migracja bez wycofania nie
     * przemknie przez CI pod pretekstem „przecież tamta też jest pusta".
     */
    public const WYCOFANIE_NIC_NIE_ROBI = 'Rozszerzeń PostgreSQL (pgcrypto, pg_trgm, unaccent) nie '
        .'zdejmujemy przy wycofaniu: `DROP EXTENSION` przewróciłby się o każdy indeks, każdą kolumnę '
        .'i każdą funkcję, która z nich korzysta, a w wariancie CASCADE skasowałby je razem z nimi. '
        .'Rozszerzenie zostawione w bazie nie przeszkadza niczemu — `CREATE EXTENSION IF NOT EXISTS` '
        .'w `up()` przyjmie je z powrotem bez zmian.';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // Schemat podany JAWNIE, nie „ten pierwszy z search_path".
        //
        // Od PostgreSQL 17 `CREATE INDEX` i `REINDEX` chodzą z ograniczonym
        // `search_path`, więc `kuking_normalize()` musi wołać `public.unaccent`
        // z pełną kwalifikacją (patrz 2026_09_05_001300_fix_search_indexes).
        // Skoro tam zapisujemy `public.`, to tutaj musimy mieć pewność, że
        // rozszerzenie naprawdę tam wyląduje — inaczej para się rozjedzie
        // i zobaczymy to dopiero przy budowaniu indeksu.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public');
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
