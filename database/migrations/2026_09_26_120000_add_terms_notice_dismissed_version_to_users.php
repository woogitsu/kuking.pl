<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasek „Zmieniliśmy regulamin” — która wersja została zamknięta (#1811, D-306).
 *
 * `users.terms_notice_dismissed_version` to DATA WERSJI regulaminu
 * (`kuking.zgody.wersja_regulaminu`), przy której osoba zamknęła pasek.
 * `NULL` = jeszcze żadnego nie zamknęła. Pasek widzi konto założone przed
 * dniem wersji, którego wartość jest pusta albo starsza od bieżącej wersji
 * (`App\Domain\Zgody\ZmianaRegulaminu`).
 *
 * Kolumna nullable bez domyślnej wartości — w PostgreSQL to zmiana samego
 * katalogu, bez przepisywania `users` (AGENTS.md §6, `lock_timeout` dokłada
 * `LimitBlokadMigracji`). Bez backfillu: każde konto sprzed wersji ma pasek
 * zobaczyć.
 *
 * ROLLBACK (D-088, wzorem `pwa_prompt_state`): `down()` ODMAWIA, gdy choć
 * jedno konto pasek zamknęło. Po cofnięciu i ponownym `migrate` kolumna
 * wróciłaby pusta, więc pasek „jednorazowy” pokazałby się drugi raz każdemu,
 * kto go już zamknął — a data zamknięcia jest jedynym śladem, że komunikat
 * o zmianie do tej osoby dotarł. Przy samych `NULL` (świeża baza, dzień
 * przed pierwszą zmianą) rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('terms_notice_dismissed_version')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                // Bez blokady nowe zamknięcie paska mogłoby powstać między
                // sprawdzeniem a usunięciem kolumny.
                DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            }

            $ile = DB::table('users')->whereNotNull('terms_notice_dismissed_version')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Nie można cofnąć migracji paska zmiany regulaminu: '.$ile.' kont(a) zamknęło już pasek, '
                    .'a po ponownym migrate zobaczyłyby go drugi raz i zniknąłby ślad, że komunikat do nich dotarł. '
                    .'Zostaw kolumnę users.terms_notice_dismissed_version i wycofaj sam kod paska.',
                );
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('terms_notice_dismissed_version');
            });
        });
    }
};
