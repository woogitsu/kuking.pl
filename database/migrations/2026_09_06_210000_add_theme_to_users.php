<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wybór jasnego/ciemnego wyglądu (issue #zgłoszenie właściciela, „tryb nocny
 * sam się włącza w telefonie").
 *
 * CO SIĘ ZEPSUŁO
 * Arkusz stylów włączał ciemny motyw sam, przez `@media
 * (prefers-color-scheme: dark)` — czyli za każdym razem, gdy telefon miał
 * włączony harmonogram "tryb nocny" albo system był ustawiony na ciemny
 * z jakiegokolwiek innego powodu. Nikt tego nie zamawiał, a część naszej
 * grupy (50+) nie kojarzy, że to WŁASNY telefon zmienił wygląd strony —
 * dla niej to wygląda jak awaria serwisu.
 *
 * DLACZEGO KOLUMNA NA KONCIE, A NIE TYLKO COOKIE
 * Dokładnie ten sam wybór co przy `text_scale` (patrz migracja
 * `0001_01_01_000001_create_users_table`): ustawienie ma przetrwać zmianę
 * przeglądarki i urządzenia. Osoba, która raz wyłączyła tryb ciemny na
 * telefonie, nie może go dostać z powrotem po zalogowaniu się na komputerze.
 * Gość (bez konta) dostaje ten sam wybór w ciasteczku — patrz
 * `App\Http\Controllers\ThemeController` i `docs/DECISIONS.md`.
 *
 * DLACZEGO TYLKO 'light'/'dark', BEZ TRZECIEJ WARTOŚCI „JAK W SYSTEMIE"
 * To jest świadome uproszczenie, nie przeoczenie. Cały sens tej zmiany to
 * PRZESTAĆ dziedziczyć motyw z systemu bez pytania — dodanie opcji „jak
 * w systemie" przywróciłoby dokładnie to zachowanie dla każdego, kto by ją
 * wybrał (albo zostawił, gdyby była domyślna), czyli ten sam efekt nocny,
 * który właściciel zgłosił jako błąd. Dwie jawne wartości, jasny domyślny,
 * są prostsze do wytłumaczenia komuś, kto nie wie, co to jest
 * „prefers-color-scheme” — a to jest dokładnie nasza grupa docelowa.
 *
 * DOMYŚLNA WARTOŚĆ ZAMIAST MIGRACJI DANYCH
 * `DEFAULT 'light'` wypełnia wszystkie istniejące konta w jednym kroku, bez
 * przepisywania tabeli. Każde konto, także już istniejące, wychodzi z tej
 * migracji w jasnym motywie — zgodnie z poleceniem właściciela („Jasny
 * zawsze domyślny”).
 *
 * ROLLBACK
 * `down()` zdejmuje CHECK i kasuje kolumnę. Traci się wyłącznie WYBÓR
 * WYGLĄDU — żadne konto, wpis ani zdjęcie nie ginie. Strona wraca do stanu
 * sprzed tej zmiany (czyli, dopóki nie wróci też arkusz stylów: znowu
 * z motywem sterowanym przez `prefers-color-scheme`). Bezpieczne na
 * produkcji w trakcie awarii:
 *
 *     php artisan migrate:rollback --step=1
 *
 * Kolejność w `down()` jest odwrotna do `up()` — najpierw ograniczenie,
 * potem kolumna — z tego samego powodu co w `..._add_display_mode_to_posts`:
 * kasowanie kolumny z zależnym CHECK-iem wymagałoby CASCADE, a CASCADE
 * w rollbacku kasuje więcej, niż się prosiło.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme', 10)->default('light');
        });

        if ($this->isPostgres()) {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme IN ('light','dark'))");
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
