<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adres e-mail unikalny bez rozróżniania wielkości liter (issue #109).
 *
 * TO NIE JEST TEN SAM BŁĄD, CO „KONTO Jan@… NIE DO ZALOGOWANIA”
 * Tamten jest naprawiony: mutator `email()` w `App\Models\User` normalizuje
 * adres przy każdym zapisie przez Eloquenta, a `findByLogin()` normalizuje po
 * obu stronach. To jest warstwa NIŻEJ — dziś ta reguła nie obowiązuje w bazie.
 *
 * `$table->string('email')->unique()` porównuje BAJTY, więc `Jan@example.com`
 * i `jan@example.com` to dla PostgreSQL dwa różne adresy. Każda ścieżka zapisu
 * omijająca mutator — `DB::table('users')->insert()`, seeder, przyszły import,
 * ręczna naprawa danych w `psql` podczas incydentu — może więc założyć drugie
 * konto na ten sam adres. Wtedy `findByLogin()` znajduje jedno z dwóch kont
 * (to, które PostgreSQL zwróci pierwsze), a człowiek loguje się raz tu, raz
 * tam i widzi „zniknięte” przepisy. Odzyskanie hasła dotyczy tylko jednego
 * z kont — i nie ma jak zgadnąć którego.
 *
 * AGENTS.md §6 mówi wprost: „prawdziwe klucze obce i prawdziwe CHECK-i
 * w bazie — walidacja w PHP jest dodatkiem, nie zamiennikiem”. Dla e-maila
 * było odwrotnie: PHP było jedyną linią obrony.
 *
 * DLACZEGO INDEKS FUNKCYJNY, A NIE `citext`
 * `citext` zmieniłby typ kolumny i zachowanie KAŻDEGO porównania w całym
 * kodzie, także tam, gdzie nikt się tego nie spodziewa. Indeks na
 * `lower(email)` robi dokładnie jedną rzecz — pilnuje, kto może zająć adres —
 * i jest tym samym rozwiązaniem, które ma już `profiles_username_lower_unique`.
 *
 * STARY INDEKS `users_email_unique` ZOSTAJE. Nowy jest od niego silniejszy,
 * ale stary obsługuje zwykłe `where('email', ?)` z `findByLogin()`; indeks
 * funkcyjny takiego zapytania nie obsłuży, a przepisywanie wszystkich odpytań
 * na `whereRaw('lower(email) = ?')` to szersza zmiana niż ta migracja.
 *
 * ROLLBACK: `down()` kasuje sam indeks (`DROP INDEX IF EXISTS`). Jest bezpieczny
 * i bezstratny — nie rusza ani jednego wiersza, a `users_email_unique` zostaje
 * nietknięty przez cały czas, więc nawet w trakcie rollbacku adres nie może się
 * zduplikować co do znaku.
 */
return new class extends Migration
{
    private const INDEX = 'users_email_lower_unique';

    public function up(): void
    {
        // Gdyby w bazie były już dwa konta różniące się tylko wielkością liter,
        // CREATE UNIQUE INDEX padnie komunikatem PostgreSQL, z którego nie
        // wynika, co zrobić. Sprawdzamy to sami i mówimy wprost, KTÓRY adres
        // koliduje.
        //
        // Świadomie NIE scalamy tu kont ani nie kasujemy żadnego z nich.
        // Migracja, która po cichu ubija czyjeś konto, jest znacznie gorsza od
        // migracji, która się nie wykonuje: to są przepisy człowieka, a decyzję
        // podejmuje człowiek, nie skrypt.
        $kolizje = DB::table('users')
            ->selectRaw('lower(email) as adres, count(*) as ile')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('adres')
            ->all();

        if ($kolizje !== []) {
            throw new RuntimeException(
                'W tabeli users są już adresy różniące się tylko wielkością liter: '
                .implode(', ', $kolizje).'. '
                .'Zdecyduj, które konto zostaje, przenieś z drugiego treści i uruchom migrację ponownie.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON users (lower(email))');
    }

    public function down(): void
    {
        Schema::table('users', function (): void {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        });
    }
};
