<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pwa_prompt_state', 16)->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_pwa_prompt_state_check CHECK (pwa_prompt_state IN ('eligible', 'offered', 'requested', 'dismissed', 'installed'))");
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $postgres = Schema::getConnection()->getDriverName() === 'pgsql';
            if ($postgres) {
                // Bez blokady nowa odmowa mogłaby powstać między sprawdzeniem
                // a usunięciem kolumny. Blokada trwa do końca transakcji DDL.
                DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            }

            // Sama kwalifikacja nie jest decyzją. Od offered pamiętamy już,
            // że jednorazowej zachęty nie wolno pokazać ponownie (D-088).
            $count = DB::table('users')->whereIn('pwa_prompt_state', [
                'offered', 'requested', 'dismissed', 'installed',
            ])->count();

            if ($count > 0) {
                throw new RuntimeException(
                    'Nie można cofnąć migracji zachęty PWA: utracono by jednorazowość lub decyzję użytkownika. '
                    .'Liczba kont z zachowanym stanem: '.$count.'. '
                    .'Pozostaw kolumnę pwa_prompt_state; wycofaj kod zachęty bez cofania tej migracji.',
                );
            }

            if ($postgres) {
                DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_pwa_prompt_state_check');
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('pwa_prompt_state');
            });
        });
    }
};
