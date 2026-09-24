<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KARA ODŁOŻONA NA CZAS USUWANIA KONTA (issue #980).
 *
 * `users.status` niósł dwa niezależne procesy — karę moderacyjną
 * (`suspended`/`banned`) i cykl usunięcia (`pending_delete` → `erased`) —
 * a każda operacja nadpisywała go bez patrzenia na drugi. Ban po zgłoszeniu
 * usunięcia wyjmował konto spod egzekutora karencji (ten wybiera
 * `status = 'pending_delete'`), a zgłoszenie usunięcia po banie gubiło karę:
 * cofnięcie usunięcia stawiało `active`.
 *
 * KONTRAKT: dopóki konto jest w cyklu usunięcia, `status` mówi o USUWANIU
 * (to on steruje egzekucją, ukryciem treści i zamknięciem konta), a kara
 * czeka w `punishment_status` (+ termin w `punishment_expires_at`).
 * Cofnięcie usunięcia przywraca karę zamiast `active`; wymazanie danych
 * zostawia ją na wierszu jako zapis stanu konta w chwili wymazania
 * (autorytatywny zapis decyzji żyje w `moderation_actions`, ADR_RETENCJE).
 * Przejścia wykonuje `User` pod blokadą wiersza — patrz `User::przejdz()`.
 *
 * CHECK-I (AGENTS.md §6):
 *  - słownik: tylko `suspended` albo `banned`;
 *  - kara odłożona istnieje wyłącznie przy `pending_delete`/`erased` —
 *    w każdym innym stanie kara stoi w `status` i dwie kopie by się rozjechały;
 *  - termin tylko przy odłożonym zawieszeniu (odpowiednik
 *    `users_status_expires_at_check`).
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedno konto ma odłożoną karę.
 * Stary schemat nie ma gdzie jej zapisać, więc po cofnięciu kodu cofnięcie
 * usunięcia zdjęłoby ban — dokładnie usterka #980. Na bazie bez takich kont
 * cofnięcie przechodzi bez pytania i nic nie ginie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('punishment_status', 20)->nullable()->after('status_expires_at');
            $table->timestampTz('punishment_expires_at')->nullable()->after('punishment_status');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_punishment_status_check
            CHECK (punishment_status IS NULL OR punishment_status IN ('suspended','banned'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_punishment_status_deletion_check
            CHECK (punishment_status IS NULL OR status IN ('pending_delete','erased'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_punishment_expires_at_check
            CHECK (punishment_expires_at IS NULL OR punishment_status = 'suspended')
        SQL);
    }

    public function down(): void
    {
        // Strażnik PRZED jakąkolwiek zmianą (ten sam wzorzec co
        // `2026_09_07_500000_add_erased_status_and_delete_scope_to_users`).
        $zKara = DB::table('users')->whereNotNull('punishment_status')->count();

        if ($zKara > 0) {
            throw new RuntimeException(
                'Liczba kont z karą odłożoną na czas usuwania (users.punishment_status): '.$zKara.'. '.
                'Stary schemat nie ma gdzie jej zapisać: po cofnięciu cofnięcie usunięcia konta '.
                "ustawiłoby `active` i zdjęło ban albo zawieszenie (issue #980). Migracja odmawia.\n\n".
                "CO ZROBIĆ:\n".
                '  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — stary kod nie czyta '.
                "tych kolumn i działa z nimi bez zmian;\n".
                "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz stan PRZED cofnięciem:\n".
                "      SELECT id, status, punishment_status, punishment_expires_at FROM users WHERE punishment_status IS NOT NULL;\n".
                '    i rozstrzygnij każde konto ręcznie (moderator), zanim ktoś cofnie jego usunięcie.',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_punishment_expires_at_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_punishment_status_deletion_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_punishment_status_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['punishment_expires_at', 'punishment_status']);
        });
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
