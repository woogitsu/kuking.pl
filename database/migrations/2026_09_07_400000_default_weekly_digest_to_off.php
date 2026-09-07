<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zgoda na cotygodniowy przegląd przestaje być domyślnie włączona.
 *
 * CO BYŁO ŹLE
 * `0001_01_01_000001_create_users_table.php:42` zakładało kolumnę
 * `wants_weekly_digest` z `default(true)`, a formularz rejestracji o tę
 * zgodę NIE PYTA — `resources/views/auth/register.blade.php` ma tylko
 * `age_confirmed` i `terms_accepted`, a `RegisterController` tego pola
 * nie ustawia. Każde nowe konto wstawało więc z zapisem na przegląd,
 * o który nikt go nie zapytał. Zgoda, o którą nie zapytano, nie jest
 * zgodą — a polityka prywatności opiera tę wysyłkę na art. 6 ust. 1
 * lit. a RODO, czyli właśnie na zgodzie.
 *
 * DLACZEGO WOLNO PRZESTAWIĆ TAKŻE ISTNIEJĄCE WIERSZE, A NIE TYLKO DEFAULT
 * Bo nie ma czego stracić, i jedno i drugie jest zmierzone:
 *   1. Zgody nie da się dziś wyrazić PRZY REJESTRACJI — pola nie ma
 *      w formularzu, więc żadne `true` w bazie nie pochodzi z decyzji
 *      człowieka. Pochodzi z tego `DEFAULT`.
 *   2. Cotygodniowego przeglądu NIE MA w kodzie w ogóle: zero
 *      mailable'i, zero notyfikacji, zero jobów, a żadne odczytanie tej
 *      kolumny nie jest klauzulą `where` wybierającą odbiorców. Nic nie
 *      zostało wysłane, więc nikomu nie odbieramy zapisu, który
 *      działał.
 * Kto naprawdę chce ten przegląd, włącza go jednym haczykiem na
 * `/ustawienia/prywatnosc` — ekran istnieje i działa (pilnuje tego test
 * kontrolny w `ZgodaNaPrzegladNieJestDomyslnaTest`).
 *
 * GDYBY TEGO NIE ZROBIĆ TERAZ, pułapka wypaliłaby w dniu, w którym ktoś
 * dopisze wysyłkę: wszystkie istniejące konta są już zapisane, bez ani
 * jednego kliknięcia. Taniej jest przestawić kolumnę, póki nie ma czego
 * wysyłać, niż tłumaczyć się z pierwszej wysyłki.
 *
 * PLAN COFNIĘCIA
 * `down()` przywraca `DEFAULT true` dla nowych wierszy i NIE dotyka
 * istniejących. To jest świadoma asymetria: cofnięcie migracji jest
 * operacją techniczną i nie może samo z siebie zapisać ludzi na
 * wysyłkę. Kto chce wrócić do stanu sprzed migracji w całości, musi
 * przestawić wiersze osobnym, jawnym `UPDATE` — i wtedy jest to
 * decyzja człowieka, a nie skutek uboczny rollbacku.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sam `DEFAULT` — dla każdej drogi tworzenia konta, nie tylko
        // dla kontrolera rejestracji (seeder, komenda, import).
        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false');

        // Istniejące wiersze: patrz uzasadnienie w nagłówku. Warunek na
        // `true` zamiast bezwarunkowego `UPDATE` po to, żeby nie ruszać
        // wierszy, które już są na `false` (m.in. kont po anonimizacji,
        // gdzie `EraseAccountData` ustawia to jawnie) — mniejszy zapis
        // i czytelniejszy ślad w logu bazy.
        DB::table('users')->where('wants_weekly_digest', true)->update([
            'wants_weekly_digest' => false,
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT true');
    }
};
