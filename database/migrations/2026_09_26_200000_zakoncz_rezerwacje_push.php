<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UUID odróżnia grupy zajęte w tym samym ułamku sekundy, a koniec prób
 * odróżnia zajęty slot od trwale porzuconej grupy (#1992).
 * NULL-owe kolumny bez defaultu nie przepisują gorącej tabeli notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuid('push_grupa_id')->nullable();
            $table->timestampTz('push_zakonczono_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('notifications')->whereNotNull('push_grupa_id')->orWhereNotNull('push_zakonczono_at')->exists()) {
            throw new RuntimeException(
                'Wycofanie odmówione (#1992): są grupy lub zakończone rezerwacje push. '
                .'Utrata identyfikatora albo znacznika zmieniłaby działanie limitu. '
                .'Wycofaj najpierw kod, pozostawiając kolumnę do osobnej decyzji o danych.',
            );
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['push_grupa_id', 'push_zakonczono_at']);
        });
    }
};
