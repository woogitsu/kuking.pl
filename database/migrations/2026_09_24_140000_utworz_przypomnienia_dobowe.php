<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `przypomnienia_dobowe` — „ten list już dziś wyszedł" (issue #1333).
 *
 * `kuking:pilnuj-terminow-odwolan` obiecuje JEDEN list na dobę, ale każde
 * wywołanie z zaległym odwołaniem kolejkowało nowy. `withoutOverlapping()`
 * chroni przed dwoma przebiegami NARAZ, nie przed drugim przebiegiem tego
 * samego dnia (ręczne ponowienie, restart, zdublowany harmonogram).
 *
 * DLACZEGO TABELA, A NIE `Cache::add()`: `docker/entrypoint.sh` czyści cache
 * przy KAŻDYM starcie kontenera, czyli dokładnie w sytuacji „restart procesu"
 * z opisu usterki. Rezerwacja musi przeżyć wdrożenie.
 *
 * Klucz główny (rodzaj, doba, odbiorca) jest rezerwacją: `insertOrIgnore`
 * na nim jest atomowy także przy dwóch równoległych przebiegach. `odbiorca`
 * to SHA-256 adresu, nie adres — tabela nie potrzebuje e-maila, żeby
 * rozpoznać duplikat, a zmiana adresu alarmowego daje nowy klucz.
 *
 * ROLLBACK NIE ODMAWIA (D-088 nie dotyczy): wiersz nie jest decyzją
 * człowieka, tylko znacznikiem deduplikacji. Zrzucenie tabeli kosztuje
 * najwyżej jeden powtórzony list tego samego dnia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('przypomnienia_dobowe', function (Blueprint $table): void {
            $table->string('rodzaj', 64);
            $table->date('doba');
            $table->char('odbiorca', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['rodzaj', 'doba', 'odbiorca']);
        });

        DB::statement('ALTER TABLE przypomnienia_dobowe ADD CONSTRAINT przypomnienia_dobowe_odbiorca_sha256_check '
            ."CHECK (odbiorca ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE przypomnienia_dobowe ADD CONSTRAINT przypomnienia_dobowe_rodzaj_niepusty_check '
            ."CHECK (rodzaj <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('przypomnienia_dobowe');
    }
};
