<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wyłącznik życzeń urodzinowych na `/home` (issue #1755, etap b).
 *
 * DOMYŚLNIE WŁĄCZONE, bo samo podanie daty jest już wyborem „chcę życzeń".
 * Wyłącznik istnieje od pierwszego dnia z tego samego powodu co
 * `memories_enabled`: urodziny po śmierci bliskiej osoby bywają trudne,
 * a konta bywają prowadzone przez rodzinę (research §5, zasada żałoby).
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy ktokolwiek wyłączył życzenia —
 * po cyklu `rollback` → `migrate` kolumna wróciłaby z `DEFAULT true`, czyli
 * życzenia włączyłyby się same osobie, która prosiła, żeby ich nie było.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('birthday_wishes_enabled')->default(true);
        });
    }

    public function down(): void
    {
        $wylaczonych = DB::table('users')->where('birthday_wishes_enabled', false)->count();

        if ($wylaczonych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba kont z wyłączonymi życzeniami urodzinowymi '
                .'(birthday_wishes_enabled = false): '.$wylaczonych.'. Po cofnięciu i ponownym '
                .'`migrate` kolumna wróciłaby z DEFAULT true — życzenia włączyłyby się same '
                ."osobie, która je wyłączyła (D-088).\n\n"
                ."CO ZROBIĆ:\n"
                .'  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '
                ."nie czyta tej kolumny;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę przed cofnięciem:\n"
                ."      SELECT id FROM users WHERE birthday_wishes_enabled = false;\n"
                .'    i odtwórz ją tym samym `UPDATE`, zanim strona główna pokaże komuś życzenia.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('birthday_wishes_enabled');
        });
    }
};
