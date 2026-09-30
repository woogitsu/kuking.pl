<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ZWYKŁY INDEKS `collection_items (collection_id)` (audyt wydajności, P3 W6).
 *
 * `collection_items` ma dwa UNIKALNE indeksy CZĘŚCIOWE z `collection_id` jako
 * kolumną wiodącą — `(collection_id, recipe_id) WHERE recipe_id IS NOT NULL`
 * i `(collection_id, post_id) WHERE post_id IS NOT NULL`. Zapytanie po samym
 * `collection_id` nie implikuje żadnego z tych predykatów, więc planista
 * nie może z nich skorzystać i idzie Seq Scanem:
 *
 *   • flagi „zapisane przeze mnie" (`ZapisyWpisu`): `collection_items` łączone
 *     z `collections` po `collection_id`;
 *   • kaskada `ON DELETE CASCADE` z `collections` (skasowanie zeszytu albo
 *     konta) — każdy skasowany zeszyt skanował całą tabelę.
 *
 * Indeks budujemy `CONCURRENTLY` poza transakcją (AGENTS.md §6): to istniejąca,
 * gorąca tabela. Niedokończony (INVALID) indeks po przerwanej budowie zdejmujemy
 * przed ponowną próbą, bo samo `IF NOT EXISTS` by go przepuściło. Wołana w już
 * otwartej transakcji (test z `RefreshDatabase`) migracja buduje zwykłym
 * `CREATE INDEX` — `artisan migrate` tego przypadku nie ma.
 *
 * ROLLBACK: `down()` zdejmuje indeks (`DROP INDEX CONCURRENTLY IF EXISTS`).
 * Bezstratnie — indeks nie niesie danych ani decyzji człowieka, więc D-088
 * nie ma tu czego chronić; wracają tylko skany.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEKS = 'collection_items_collection_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $wspolbieznie = $this->wspolbieznie();

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS.' ON collection_items (collection_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX '.$this->wspolbieznie().'IF EXISTS '.self::INDEKS);
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
