<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kod zamknięcia rezerwacji Web Push (#2053).
 *
 * `push_zakonczono_at` (#1992) mówi TYLKO, że próby się skończyły. Tę samą
 * wartość stawiała trwała porażka transportu (wyczerpane próby) i świadome
 * anulowanie ponowienia (#2052: przeczytane, niewidoczne, zablokowane konto,
 * rezerwacja starsza niż 48 h). Czujka `kuking:sprawdz-push` musi odróżnić
 * awarię od anulowania bez czytania dziennika — stąd krótki kod w bazie.
 *
 * WSZYSTKO NA ŻYWEJ TABELI (AGENTS.md §6): kolumna nullable bez defaultu
 * (zmiana katalogu, bez przepisywania), CHECK jako `NOT VALID` + osobne
 * `VALIDATE CONSTRAINT`, częściowy indeks `CONCURRENTLY` — stąd
 * `$withinTransaction = false`.
 *
 * ROLLBACK NIE ODMAWIA (D-088 świadomie nie dotyczy): kod nie jest decyzją
 * człowieka o jego danych ani widoczności. Po `down()` i ponownym `migrate`
 * zamknięte grupy wracają bez kodu, czyli jako „zamknięte, nie alarmują" —
 * ginie wyłącznie diagnostyka czujki, nikt nie dostaje pushu drugi raz
 * (`push_proba_at` i `push_zakonczono_at` zostają nietknięte).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'notifications_push_wynik_check';

    private const INDEKS = 'notifications_push_nierozliczone_idx';

    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'push_wynik')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->string('push_wynik', 32)->nullable();
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Kod ma sens tylko przy zamkniętej rezerwacji; lista jest zamknięta.
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_push_wynik_check CHECK ('
            ."push_wynik IS NULL OR (push_zakonczono_at IS NOT NULL AND push_wynik IN ('porazka_transportu', 'anulowano', 'zamknieto_recznie'))"
            .') NOT VALID');
        DB::statement('ALTER TABLE notifications VALIDATE CONSTRAINT '.self::OGRANICZENIE);

        // Przerwane CONCURRENTLY zostawia indeks INVALID, który IF NOT EXISTS by przepuściło.
        if (DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEKS);
        }

        // Tylko wiersze, które czujka ogląda: rezerwacje bez wysyłki, które
        // są jeszcze otwarte albo zamknęła trwała porażka. Mały z natury.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_push_nierozliczone_idx ON notifications (push_proba_at) '
            .'WHERE push_proba_at IS NOT NULL AND push_wyslano_at IS NULL '
            ."AND (push_zakonczono_at IS NULL OR push_wynik = 'porazka_transportu')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Test może wołać down() w otwartej transakcji, gdzie CONCURRENTLY nie działa.
            $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        }

        if (Schema::hasColumn('notifications', 'push_wynik')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropColumn('push_wynik');
            });
        }
    }
};
