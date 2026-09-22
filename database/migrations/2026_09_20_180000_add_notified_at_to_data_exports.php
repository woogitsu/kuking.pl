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
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->timestampTz('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        // NAJPIERW PYTAMY O ISTNIENIE, POTEM O DANE.
        //
        // `down()` bywa wołane tam, gdzie tabeli albo kolumny już nie ma:
        // przy `migrate:refresh` na bazie bez pełnego schematu (dokładnie to
        // robi krok „Odwracalność migracji" w `scripts/check.sh`) albo gdy
        // ktoś cofa migracje drugi raz. Zapytanie wprost o `data_exports`
        // wywracało wtedy całe wycofanie surowym SQLSTATE[42P01], zamiast
        // po cichu nie mieć nic do roboty. Migracja, której `down()` wybucha
        // przy braku tabeli, nie jest odwracalna w czasie awarii — a po to
        // ten `down()` istnieje.
        if (! Schema::hasTable('data_exports') || ! Schema::hasColumn('data_exports', 'notified_at')) {
            return;
        }

        // Utrata znacznika po down/up pozwoliłaby wysłać ten sam list ponownie.
        // To jest świadoma ODMOWA wycofania (D-088, AGENTS.md §6), nie usterka:
        // decyzję o porzuceniu znaczników podejmuje człowiek, nie migracja.
        if (DB::table('data_exports')->whereNotNull('notified_at')->exists()) {
            throw new RuntimeException('Zachowaj kopię data_exports.notified_at i zakończ zadania NotifyUserExportReady przed ręcznym wycofaniem tej kolumny.');
        }

        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
