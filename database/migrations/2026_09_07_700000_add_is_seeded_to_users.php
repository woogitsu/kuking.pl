<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.is_seeded` — konto z treści zalążkowej wchodzi na produkcję, ale
 * JAWNIE OZNACZONE (D-025, `docs/DECISIONS.md`).
 *
 * SKĄD TEN WYBÓR KSZTAŁTU: `tags.is_seeded`, NIE NOWY SYSTEM
 * D-025 rozstrzygnęła pytanie „pusty serwis czy treść" na rzecz treści
 * zalążkowej — dwanaście kont-person z `database/seeders/dane/
 * tresc-zalazkowa.json` — pod warunkiem, że są WIDOCZNIE OZNACZONE jako
 * przykładowe. Ten warunek potrzebuje jednej rzeczy: sposobu odróżnienia
 * takiego konta od konta założonego przez człowieka.
 *
 * `tags.is_seeded` (migracja `2026_09_07_100000_create_tags_tables`)
 * rozwiązała dokładnie to samo pytanie dla tagów, komentarzem wprost:
 * „atrybut pochodzenia danych, NIE osobny system widoczny dla użytkownika".
 * Ten sam kształt przenosi się tu bez zmian i z tego samego powodu — to
 * jest fakt o TYM, SKĄD KONTO POCHODZI (z pliku danych, nie z rejestracji),
 * a nie nowa oś widoczności obok `status`/`role`. Konto z `is_seeded = true`
 * przechodzi przez WSZYSTKIE te same bramki co każde inne: `status` decyduje,
 * czy może czytać/pisać, `role` — czy moderuje. `is_seeded` nie zastępuje
 * ani nie omija żadnej z nich, tylko dokłada etykietę w interfejsie
 * (D-025: „przy koncie, nie tylko w regulaminie") i wyklucza konto z metryk
 * North Star (`App\Domain\Analytics\CookEligibility`) — dokładnie tak, jak
 * gospodarz i konta testowe już są wykluczone tamtędy (issue #114).
 *
 * DLACZEGO NA `users`, A NIE NA `profiles`
 * Etykieta ma się pokazywać wszędzie, gdzie serwis pokazuje AUTORA —
 * profil, karta wpisu, karta przepisu, komentarz (D-025) — a każde z tych
 * miejsc i tak już ładuje `User` (przez `$post->author`, `$recipe->author`,
 * `$comment->author`), podczas gdy `profiles` bywa ładowany osobno albo
 * wcale (np. `Comment::author` nie musi znać profilu, żeby policzyć
 * uprawnienia). Trzymanie znacznika pochodzenia przy koncie, obok
 * `status`/`role`, jest też zgodne z tym, że to jest fakt o KONCIE
 * (kto może się nim posługiwać), nie o publicznej twarzy (jak wygląda).
 *
 * DOMYŚLNIE `false`, BEZ BACKFILLU
 * Żadne istniejące konto w żadnym środowisku nie pochodzi z tego pliku —
 * seeder czytający `tresc-zalazkowa.json` (`Database\Seeders\
 * TrescZalazkowaSeeder`) dopiero powstaje w tym samym zestawie zmian i to
 * on, nie ta migracja, oznacza dwanaście kont. Backfill nie ma więc czego
 * przenosić: `false` dla każdego wiersza jest już poprawną odpowiedzią.
 *
 * ŚWIADOMIE POZA `User::$fillable`
 * Ten sam powód, dla którego nie ma tam `status` ani `role` (komentarz przy
 * `User::$fillable`, AGENTS.md §7): to jest fakt o POCHODZENIU konta,
 * ustawiany raz, w jednym miejscu (`TrescZalazkowaSeeder`, wprost przez
 * `DB::table('users')->insert()` — ten sam wzorzec zapisu co
 * `TagSeeder::utworzBrakujaceTagi()`), nie coś, co formularz rejestracji
 * albo edycji profilu mógłby kiedykolwiek przypadkiem przekazać dalej.
 *
 * ROLLBACK
 * `down()` zdejmuje kolumnę. Bezpieczne: nic poza etykietą w interfejsie
 * i wykluczeniem z WAC nie czyta tej kolumny, więc po rollbacku dwanaście
 * kont z pliku staje się (w bazie) nie do odróżnienia od kont zwykłych —
 * ich treść ZOSTAJE (rollback tej migracji nigdy nie kasuje wierszy
 * `users`/`posts`/`recipes`/`comments`), znika tylko etykieta i wykluczenie
 * z metryki. To jest znany, opisany skutek, nie utrata danych.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_seeded')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_seeded');
        });
    }
};
