<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nazwa użytkownika unikalna bez rozróżniania wielkości liter (audyt A25).
 *
 * Walidacja w PHP (`App\Rules\UsernameNotTaken`) łapie zwykłą rejestrację,
 * ale między sprawdzeniem a zapisem jest okno — dwa równoczesne żądania
 * przechodzą walidację i oba zapisują. Poza tym walidator nie obowiązuje
 * seedera, konsoli ani przyszłego importu. Dlatego prawdziwa gwarancja
 * stoi w bazie (AGENTS.md §6), a walidacja jest po to, żeby człowiek
 * dostał komunikat zamiast błędu 500.
 *
 * Indeks jest funkcyjny — na `lower(username)`, a nie na `username`.
 * Dzięki temu nazwy zostają zapisane tak, jak ktoś je wpisał: „AniaGotuje"
 * zostaje „AniaGotuje", a nie zamienia się w „aniagotuje". Rozróżnienie
 * dotyczy tylko tego, kto może zająć nazwę.
 *
 * Ten sam indeks obsługuje wyszukiwanie po `lower(username)` w logowaniu
 * i na profilu publicznym — bez niego byłby to pełny skan tabeli.
 */
return new class extends Migration
{
    private const INDEX = 'profiles_username_lower_unique';

    public function up(): void
    {
        // Jeżeli baza ma już parę w rodzaju „basia" i „Basia", CREATE UNIQUE
        // INDEX padnie komunikatem PostgreSQL, z którego nie wynika, co zrobić.
        // Sprawdzamy to sami i mówimy wprost, KTÓRE nazwy kolidują.
        //
        // Świadomie NIE zmieniamy tu niczyjej nazwy. Migracja przemianowująca
        // konta po cichu jest gorsza niż migracja, która się nie wykonuje:
        // decyzję, kto zatrzymuje nazwę, podejmuje człowiek, nie skrypt.
        $kolizje = DB::table('profiles')
            ->selectRaw('lower(username) as nazwa, count(*) as ile')
            ->groupByRaw('lower(username)')
            ->havingRaw('count(*) > 1')
            ->pluck('nazwa')
            ->all();

        if ($kolizje !== []) {
            throw new RuntimeException(
                'W tabeli profiles są już nazwy różniące się tylko wielkością liter: '
                .implode(', ', $kolizje).'. '
                .'Zdecyduj, które konto zatrzymuje nazwę, zmień pozostałe i uruchom migrację ponownie.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON profiles (lower(username))');
    }

    public function down(): void
    {
        Schema::table('profiles', function (): void {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        });
    }
};
