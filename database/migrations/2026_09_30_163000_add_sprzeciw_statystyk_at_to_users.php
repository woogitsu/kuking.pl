<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprzeciw wobec statystyk (RODO art. 21, issue #2277).
 *
 * `users.sprzeciw_statystyk_at` — chwila, w której osoba kliknęła „Nie licz
 * mnie w statystykach” w ustawieniach prywatności. `NULL` = sprzeciwu nie ma.
 * Nullable `timestamptz` bez wartości domyślnej: w PostgreSQL zmiana samego
 * katalogu (AGENTS.md §6), bez backfillu.
 *
 * Polityka prywatności opiera statystyki na uzasadnionym interesie i od
 * początku pisze „możesz się temu sprzeciwić”. Do tej kolumny serwis nie miał
 * jak takiego sprzeciwu zapamiętać ani uszanować.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedno konto zgłosiło sprzeciw.
 * Po ponownym `migrate` kolumna wróciłaby pusta, czyli sprzeciw tych osób
 * zniknąłby bez śladu i serwis znów liczyłby je w statystykach. Przy samych
 * `NULL` rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('sprzeciw_statystyk_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            }

            $ile = DB::table('users')->whereNotNull('sprzeciw_statystyk_at')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Nie można cofnąć migracji sprzeciwu wobec statystyk: '.$ile.' kont(a) zgłosiło sprzeciw, '
                    .'a po ponownym migrate serwis znów liczyłby te osoby w statystykach. '
                    .'Zostaw kolumnę users.sprzeciw_statystyk_at i wycofaj sam kod przełącznika.',
                );
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('sprzeciw_statystyk_at');
            });
        });
    }
};
