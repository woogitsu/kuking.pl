<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasek „Zmieniliśmy politykę prywatności” — która wersja została zamknięta (D-327, D-332).
 *
 * `users.policy_notice_dismissed_version` to DATA WERSJI polityki
 * (`kuking.zgody.wersja_polityki`), przy której osoba zamknęła pasek.
 * `NULL` = jeszcze żadnego nie zamknęła. Bliźniak
 * `terms_notice_dismissed_version` (D-306): nullable `date` bez domyślnej
 * wartości, w PostgreSQL zmiana samego katalogu (AGENTS.md §6), bez backfillu.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedno konto pasek zamknęło —
 * data zamknięcia jest jedynym śladem, że komunikat o istotnej zmianie
 * polityki do tej osoby dotarł, a po ponownym `migrate` pasek wróciłby
 * do każdego. Przy samych `NULL` rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('policy_notice_dismissed_version')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            }

            $ile = DB::table('users')->whereNotNull('policy_notice_dismissed_version')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Nie można cofnąć migracji paska zmiany polityki prywatności: '.$ile.' kont(a) zamknęło już pasek, '
                    .'a po ponownym migrate zobaczyłyby go drugi raz i zniknąłby ślad, że komunikat do nich dotarł. '
                    .'Zostaw kolumnę users.policy_notice_dismissed_version i wycofaj sam kod paska.',
                );
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('policy_notice_dismissed_version');
            });
        });
    }
};
