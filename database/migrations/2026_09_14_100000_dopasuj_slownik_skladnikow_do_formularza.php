<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Formularz i recipe_ingredients przyjmują 240 znaków. Słownik nie
        // może odrzucać tej samej treści przy 160 (#526). Normalizacja może
        // wydłużyć zapis (Æ → ae), więc jej wynik nie ma limitu 240 znaków.
        // PostgreSQL zachowuje UNIQUE i indeks GIN przy zmianie typu.
        DB::statement('ALTER TABLE ingredients ALTER COLUMN canonical_name TYPE varchar(240), ALTER COLUMN normalized_name TYPE text');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // Sprawdzenie i zwężenie są jedną operacją: pomiędzy nimi nie
            // może wejść nowy długi składnik. D-088: nie obcinamy danych.
            DB::statement('LOCK TABLE ingredients IN ACCESS EXCLUSIVE MODE');
            if (DB::table('ingredients')->whereRaw('char_length(canonical_name) > 160 OR char_length(normalized_name) > 160')->exists()) {
                throw new RuntimeException('Nie można cofnąć rozszerzenia słownika składników: są nazwy dłuższe niż 160 znaków. Zachowaj migrację albo uzgodnij ręczne przeniesienie tych danych; cofnięcie nie obetnie ich automatycznie.');
            }
            DB::statement('ALTER TABLE ingredients ALTER COLUMN canonical_name TYPE varchar(160), ALTER COLUMN normalized_name TYPE varchar(160)');
        });
    }
};
