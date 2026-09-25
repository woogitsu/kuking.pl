<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Pokaż moje urodziny obserwującym" (issue #1755, etap d).
 *
 * DOMYŚLNIE WYŁĄCZONE — decyzja właściciela z 25.09.2026. Przypomnienie
 * („Dziś urodziny: Ania") to jedyne miejsce, w którym data urodzin wychodzi
 * poza samą osobę, a obserwować może każdy, bez akceptacji. Dlatego tylko po
 * jawnym włączeniu.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy ktokolwiek to włączył. Tu kierunek
 * „wartości domyślnej" jest bezpieczny (cofnięcie wyłączyłoby przypomnienia,
 * a nie upubliczniło daty), ale włączenie jest decyzją człowieka o
 * widoczności, a ta ginęłaby bez śladu — reguła D-088 nazywa widoczność
 * wprost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('birthday_visible_to_followers')->default(false);
        });
    }

    public function down(): void
    {
        $wlaczonych = DB::table('users')->where('birthday_visible_to_followers', true)->count();

        if ($wlaczonych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba kont z włączonym przypomnieniem o urodzinach dla '
                .'obserwujących (birthday_visible_to_followers = true): '.$wlaczonych.'. To decyzja '
                ."człowieka o widoczności, która po cofnięciu zginęłaby bez śladu (D-088).\n\n"
                ."CO ZROBIĆ:\n"
                .'  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '
                ."nie czyta tej kolumny;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę przed cofnięciem:\n"
                ."      SELECT id FROM users WHERE birthday_visible_to_followers = true;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na tę wersję schematu.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('birthday_visible_to_followers');
        });
    }
};
