<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Mój stół" — dobrowolna półka propozycji (issue #1749, D-304).
 *
 * Jedna kolumna: czy ta osoba WŁĄCZYŁA sobie półkę. Nic więcej o niej nie
 * zapisujemy — półka nie uczy się z zachowania (AGENTS.md §8, D-275), więc
 * nie ma profilu dopasowania, wag ani historii kliknięć do przechowania
 * i do resetowania. Dobór liczy się przy każdym wyświetleniu z tego, co
 * widz sam ustawił: obserwowanych tagów i ukryć (#1810).
 *
 * DOMYŚLNIE WYŁĄCZONE (`false`) — odwrotnie niż wspomnienia. Półka jest
 * propozycją serwisu, a nie własnym archiwum; issue #1749 wymaga, żeby była
 * „osobna, dobrowolna". Kto jej nie włączy, nie dostaje ani jednej propozycji.
 *
 * ROLLBACK PRZECHODZI, I TO JEST ŚWIADOME (D-088, D-304). `down()` zdejmuje
 * kolumnę bez odmowy. Ponowny `migrate` odtworzy ją z `DEFAULT false`, czyli
 * wyłączy półkę tym, którzy ją włączyli. Utracona wartość to preferencja
 * WYŚWIETLANIA (jak `theme` na liście D-088), a kierunek utraty jest
 * bezpieczny: po cyklu nikt nie widzi niczego, czego nie chciał — najwyżej
 * musi włączyć półkę jeszcze raz. D-088 każe odmawiać wtedy, gdy wartość
 * domyślna ODWRACA decyzję o danych, zgodzie albo widoczności; tu nie odwraca
 * żadnej z nich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('moj_stol_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('moj_stol_enabled');
        });
    }
};
