<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.session_generation` — generacja sesji konta (#1046).
 *
 * Rośnie przy każdym `User::invalidateSessions()`; sesja ze starszą
 * generacją jest odrzucana przez `SprawdzGeneracjeSesji`. Uzasadnienie:
 * `App\Support\Sesja\GeneracjaSesji`.
 *
 * Bez backfillu: `0` dla wszystkich i brak klucza w istniejących sesjach
 * też znaczy `0`, więc wdrożenie nikogo nie wylogowuje.
 *
 * ROLLBACK: `down()` najpierw kasuje wiersze `sessions` kont z generacją
 * > 0, potem usuwa kolumnę. Bez tego cykl down/up zerowałby licznik, a sesja
 * odrzucona przed rollbackiem (starej generacji, np. 0) stałaby się po
 * ponownym `up()` znowu zgodna — czyli rollback cicho przywracałby dostęp
 * odwołanej przeglądarce. Skutkiem kasowania jest wyłącznie ponowne
 * logowanie tych kont; żadna treść ani decyzja nie ginie, więc to nie jest
 * odmowa w rozumieniu D-088, tylko domknięcie w bezpieczną stronę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('session_generation')->default(0);
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_session_generation_check CHECK (session_generation >= 0)');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'session_generation')) {
            return;
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->whereIn('user_id', DB::table('users')->select('id')->where('session_generation', '>', 0))
                ->delete();
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_session_generation_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('session_generation');
        });
    }
};
