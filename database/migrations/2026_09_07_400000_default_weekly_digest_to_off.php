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
 * PLAN COFNIĘCIA — `down()` NIE PRZYWRACA `DEFAULT true`
 *
 * POPRAWKA Z 10 WRZEŚNIA 2026 (audyt DB2, `docs/DECISIONS.md` D-072).
 * Do tego dnia `down()` wykonywał `SET DEFAULT true`, czyli przywracał
 * DOKŁADNIE to zachowanie, przez które ta migracja powstała. Gdy ją pisano,
 * było to obronne: digestu nie było w kodzie w ogóle, więc `DEFAULT true`
 * nie kończył się żadną wysyłką. **Od 10 września wysyłka istnieje**
 * (`kuking:wyslij-podsumowania`, harmonogram codziennie o 08:30), więc
 * techniczne cofnięcie migracji zapisywałoby NOWE konta na prawdziwy mailing
 * bez ani jednego kliknięcia — a `resources/views/auth/register.blade.php`
 * nadal o tę zgodę nie pyta. Zgoda, o którą nie zapytano, nie jest zgodą,
 * a rollback jest operacją techniczną i nie ma prawa jej wytworzyć.
 *
 * ASYMETRIA JEST WIĘC TERAZ PEŁNA I JAWNA: `up()` przestawia `DEFAULT`
 * ORAZ istniejące wiersze na `false`, a `down()` nie przywraca ani jednego,
 * ani drugiego — jest pustą, świadomie bezczynną operacją. Tak, to znaczy,
 * że tej migracji NIE DA SIĘ cofnąć „wiernie historycznie". Tak ma być:
 * wierny rollback przywraca też wadę, którą migracja naprawiła, a przy
 * poczcie wychodzącej ta wada jest nieodwracalna — listu wysłanego bez zgody
 * nie da się odwołać. Bezpieczny rollback bije wierny wszędzie tam, gdzie
 * wierny wraca do stanu groźnego.
 *
 * Kto naprawdę chce wrócić do opt-outu (czyli świadomie: mailing bez pytania
 * dla nowych kont), robi to jawnym `ALTER TABLE … SET DEFAULT true` z ręki,
 * po przeczytaniu D-072 i po dodaniu pola zgody do formularza rejestracji.
 * Wtedy jest to decyzja człowieka, a nie skutek uboczny `migrate:rollback`.
 *
 * PILNUJE TEGO TAKŻE KOD, NIE TYLKO TEN KOMENTARZ: `App\Domain\Digest\
 * BramkaDomyslnejZgody` pyta `information_schema` przed każdą wysyłką i przy
 * `DEFAULT true` nie pozwala jej wystartować. Komentarz przeczyta ten, kto
 * otworzy plik — bramka zatrzyma wysyłkę także wtedy, gdy `DEFAULT` przestawi
 * ktoś zupełnie inną drogą (ręczny `ALTER`, przywrócenie bazy z kopii sprzed
 * tej migracji, `pg_restore` starego schematu). Pilnują tego
 * `RollbackNieWlaczaDigestuTest`.
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

    /**
     * ŚWIADOMIE NIE ROBI NIC — patrz „PLAN COFNIĘCIA" w nagłówku.
     *
     * Metoda ZOSTAJE, mimo że jest pusta, i to nie jest przeoczenie: bez niej
     * `migrate:rollback` przewracałby się na tej migracji, a `migrate:refresh`
     * (chodzi w CI) nie zszedłby poniżej niej. Pusta metoda mówi „cofnięcie
     * jest dozwolone i nie zmienia domyślnej wartości"; brak metody mówiłby
     * „cofnięcie jest niemożliwe" — a to nieprawda i zablokowałoby rollback
     * KAŻDEJ późniejszej migracji.
     *
     * `DEFAULT false` zostaje po cofnięciu. Nowe konta nadal wstają bez
     * zapisu na mailing, o który nikt ich nie zapytał.
     */
    public function down(): void {}
};
