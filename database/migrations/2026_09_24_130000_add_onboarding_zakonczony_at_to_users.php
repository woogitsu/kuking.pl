<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik „pierwsze kroki zakończone albo świadomie pominięte" (#985).
 *
 * `null` = konto może dostać na Starcie spokojny odnośnik „Dokończ pierwsze
 * kroki". Wartość ustawiają WYŁĄCZNIE `OnboardingController::done()`
 * i `::dismiss()`; nic jej nie zeruje.
 *
 * Backfill: konta istniejące przed tą migracją dostają znacznik — nie wiemy,
 * czy skończyły onboarding, a przypomnienie wyświetlone nagle wszystkim byłoby
 * gorsze niż brak przypomnienia dla kilku osób.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('onboarding_zakonczony_at')->nullable();
        });

        DB::table('users')->update(['onboarding_zakonczony_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Bez strażnika D-088: ponowny `up()` oznacza KAŻDE konto jako
        // zakończone, więc cofnięcie może najwyżej wyłączyć przypomnienie —
        // nigdy nie przywraca go komuś, kto kliknął „Nie przypominaj".
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('onboarding_zakonczony_at'));
    }
};
