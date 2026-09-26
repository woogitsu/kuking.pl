<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Koniec prób transportu odróżnia zajęty slot od trwale porzuconej grupy (#1992).
 * NULL-owa kolumna bez defaultu nie przepisuje gorącej tabeli notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('push_zakonczono_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('notifications')->whereNotNull('push_zakonczono_at')->exists()) {
            throw new RuntimeException(
                'Wycofanie odmówione (#1992): są zakończone rezerwacje push. '
                .'Utrata znacznika ponownie uznałaby je za aktywne i zmieniła działanie limitu. '
                .'Wycofaj najpierw kod, pozostawiając kolumnę do osobnej decyzji o danych.',
            );
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('push_zakonczono_at');
        });
    }
};
