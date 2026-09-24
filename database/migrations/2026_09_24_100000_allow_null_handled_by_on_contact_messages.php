<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Poprawka ograniczenia CHECK `contact_messages_handled_complete` (#844).
 *
 * CO BYŁO ŹLE
 * Kolumna `handled_by` ma klucz obcy z `nullOnDelete()`, ale CHECK
 * `contact_messages_handled_complete` wymagał `num_nonnulls(handled_by, handled_at) = 2`
 * przy statusie innym niż `new`. Usunięcie konta operatora, który obsłużył
 * jakąkolwiek wiadomość, wyzwalało `SET NULL` na `handled_by` — co
 * natychmiast naruszało CHECK (bo `handled_by` stawało się NULL, a
 * `handled_at` było NOT NULL) i rzucało wyjątkiem bazy (SQLSTATE 23514),
 * blokując usunięcie konta.
 *
 * CO ROBI TA MIGRACJA
 * Zmienia warunek CHECK na:
 *   (status = 'new' AND num_nonnulls(handled_by, handled_at) = 0)
 *   OR (status <> 'new' AND handled_at IS NOT NULL)
 * Dzięki temu:
 *   - nowa wiadomość nadal nie może mieć ani autora, ani daty obsługi,
 *   - obsłużona wiadomość nadal MUSI mieć `handled_at` (od którego liczy się retencja),
 *   - `handled_by` może stać się NULL, gdy konto operatora zostanie usunięte.
 *
 * ROLLBACK
 * `down()` odmawia wycofania (`RuntimeException`), jeśli w bazie istnieją
 * wiadomości o statusie innym niż `new` z `handled_by IS NULL` (powstałe
 * w wyniku usunięcia operatora), ponieważ przywrócenie starego ograniczenia
 * spowodowałoby natychmiastowy błąd spójności danych.
 * Jeśli takich wierszy nie ma, bezpiecznie przywraca poprzedni CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE contact_messages DROP CONSTRAINT IF EXISTS contact_messages_handled_complete');

        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_handled_complete '
            ."CHECK ((status = 'new' AND num_nonnulls(handled_by, handled_at) = 0) "
            ."OR (status <> 'new' AND handled_at IS NOT NULL))",
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $istniejaSieroty = DB::table('contact_messages')
            ->where('status', '<>', 'new')
            ->whereNull('handled_by')
            ->exists();

        if ($istniejaSieroty) {
            throw new RuntimeException(
                'Nie można cofnąć migracji: w tabeli contact_messages istnieją obsłużone wiadomości '
                .'z handled_by = NULL (po usuniętych operatorach). Przywrócenie starego ograniczenia '
                .'naruszyłoby spójność danych. Pozostaw migrację i wycofaj sam kod aplikacji; nie usuwaj historii.',
            );
        }

        DB::statement('ALTER TABLE contact_messages DROP CONSTRAINT IF EXISTS contact_messages_handled_complete');

        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_handled_complete '
            ."CHECK ((status = 'new' AND num_nonnulls(handled_by, handled_at) = 0) "
            ."OR (status <> 'new' AND num_nonnulls(handled_by, handled_at) = 2))",
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
