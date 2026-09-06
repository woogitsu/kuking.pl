<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik trwałego usunięcia danych po karencji (audyt A8).
 *
 * PROBLEM
 * `delete_requested_at` mówi, KIEDY konto zgłoszono do usunięcia, ale nigdzie
 * nie było zapisane, czy dane FAKTYCZNIE zostały już wymazane. Bez tego:
 *
 *  - egzekutor karencji (`kuking:usun-wygasle-konta`) nie miał jak być
 *    idempotentny — każde uruchomienie próbowałoby anonimizować konto od
 *    nowa, nadpisując np. znów wygenerowany losowy e-mail;
 *  - formularz cofnięcia usunięcia konta nie miał jak odróżnić „jeszcze da się
 *    wrócić" od „dane już nie istnieją, cofnięcie tylko wskrzesiłoby pusta
 *    powłokę konta bez treści, którą ono miało".
 *
 * Status konta ZOSTAJE `pending_delete` na zawsze po wykonaniu — nie
 * wprowadzamy nowej wartości statusu (i nie ruszamy `users_status_check`),
 * bo z punktu widzenia logowania i moderacji nic się nie zmienia: konto
 * i tak nie loguje się od momentu zgłoszenia usunięcia. `data_erased_at`
 * to jedyna nowa informacja: czy karencja już się WYKONAŁA.
 *
 * DLACZEGO CHECK, A NIE SAMA WALIDACJA W PHP (AGENTS.md §6)
 * Dane raz wymazane nie mogą „ożyć" przez pomyłkę w innym miejscu kodu —
 * kolumna ma sens wyłącznie przy koncie oznaczonym do usunięcia.
 *
 * ROLLBACK
 * `down()` zdejmuje CHECK, indeks i kolumnę. Konta, którym dane już wymazano,
 * ZOSTAJĄ wymazane — rollback schematu nie przywraca e-maila ani hasła, bo
 * tych danych po prostu już nie ma (to nie jest utrata spowodowana migracją,
 * to efekt uboczny tego, co migracja tylko ODNOTOWYWAŁA). Same konta nie
 * zmieniają zachowania: nadal nie da się ich zalogować.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('data_erased_at')->nullable()->after('delete_requested_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_data_erased_at_check
            CHECK (data_erased_at IS NULL OR status = 'pending_delete')
        SQL);

        // Indeks częściowy: egzekutor karencji pyta wyłącznie o konta
        // `pending_delete` bez wykonanej jeszcze anonimizacji. Bez WHERE
        // indeks obejmowałby wszystkie konta, z których zdecydowana
        // większość ma tu NULL.
        DB::statement(<<<'SQL'
            CREATE INDEX users_pending_erase_idx
            ON users (delete_requested_at)
            WHERE status = 'pending_delete' AND data_erased_at IS NULL
        SQL);
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_pending_erase_idx');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_data_erased_at_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('data_erased_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
