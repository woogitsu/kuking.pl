<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.weekly_digest_sent_at` — kiedy ta osoba dostała OSTATNIE tygodniowe
 * podsumowanie (issue #11, `docs/DECISIONS.md` D-057).
 *
 * PO CO TO JEST — TRZY RZECZY NARAZ, KAŻDA KONIECZNA
 *
 * 1. **„Jeden e-mail tygodniowo. Nigdy więcej"** (issue #11 pkt 4) jest
 *    obietnicą złożoną wprost na ekranie `/ustawienia/prywatnosc`. Bez
 *    znacznika po stronie KONTA nie ma czym jej dotrzymać: kolejka nie
 *    pamięta, komu już wysłała, a `notifications` nie dotyczy poczty.
 *
 * 2. **Powtórne uruchomienie zadania tego samego dnia nie może wysłać listu
 *    drugi raz.** Harmonogram bywa uruchomiony ręcznie po awarii, kontener
 *    bywa restartowany, a `withoutOverlapping()` chroni tylko przed dwoma
 *    JEDNOCZESNYMI przebiegami, nie przed dwoma po kolei. Wybór odbiorców
 *    pyta więc o ten znacznik, a nie o to, „czy zadanie już dziś chodziło".
 *
 * 3. **Wysyłka NIE MIEŚCI SIĘ W JEDNEJ DOBIE i nie ma udawać, że mieści.**
 *    Konto pocztowe ma twardy limit 300 listów na dobę (EmailLabs STARTUP,
 *    `docs/decyzje/POCZTA.md` §1), dzielony z pocztą transakcyjną. Przy 500
 *    zapisanych osobach podsumowania idą kilka dni z rzędu, w kolejności
 *    „kto czeka najdłużej" — a ta kolejność bierze się dokładnie z tej
 *    kolumny (`ORDER BY weekly_digest_sent_at ASC NULLS FIRST`).
 *
 * DLACZEGO KOLUMNA NA `users`, A NIE OSOBNA TABELA WYSYŁEK
 * Bo pytanie, które zadajemy, brzmi „kiedy OSTATNIO", a nie „co się
 * wydarzyło". Liczy się wyłącznie najnowsza wartość — poprzednia nie służy
 * niczemu, a tabela rosnąca o wiersz na osobę na tydzień wymagałaby własnej
 * retencji, własnego sprzątania i własnego wiersza w polityce prywatności.
 * To samo rozstrzygnięcie i z tego samego powodu co przy
 * `users.ostatnio_widziany_at` (migracja `..._add_last_seen_to_users_table`).
 *
 * `timestampTz`, NIE `date` — AGENTS.md §6: `timestamptz` dla czasu. Odstęp
 * liczymy w godzinach od momentu, a nie w datach ściennych, więc obie strony
 * porównania muszą być tym samym typem.
 *
 * `NULLABLE`, BEZ WARTOŚCI DOMYŚLNEJ. `NULL` znaczy „jeszcze nigdy nie
 * dostał" i to jest stan, w którym są dziś WSZYSTKIE konta — bo digest
 * nigdy nie wyszedł (potwierdza to `docs/DATABASE.md`, sekcja
 * `wants_weekly_digest`). Dzięki temu pierwszy przebieg zastaje szczery
 * stan świata, a nie sztuczne „wysłano przy migracji".
 *
 * INDEKS CZĘŚCIOWY, NIE PEŁNY
 * Zapytanie wybierające odbiorców zawsze zaczyna od `wants_weekly_digest =
 * true` — a takich kont będzie mniejszość (zgoda jest opt-in od migracji
 * `2026_09_07_400000_default_weekly_digest_to_off`). Indeks częściowy po tym
 * warunku jest więc wielokrotnie mniejszy niż pełny i obsługuje jedyne
 * zapytanie, jakie tę kolumnę czyta. `NULLS FIRST` w indeksie zgadza się
 * z `ORDER BY` w `OdbiorcyDigestu` — inaczej Postgres musiałby i tak
 * sortować wynik.
 *
 * ROLLBACK
 * Bezpieczny, ale NIE bez skutku i trzeba to nazwać: `down()` kasuje kolumnę,
 * więc razem z nią znika pamięć o tym, komu już wysłano. Po przywróceniu
 * kolumny wszyscy mają `NULL`, czyli pierwszy przebieg po rollbacku wyśle
 * list także tym, którzy dostali go wczoraj. Dlatego rollback tej migracji
 * robi się WYŁĄCZNIE razem z wyłączeniem wysyłki
 * (`KUKING_DIGEST_WLACZONY=false`), a nie „przy okazji".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('weekly_digest_sent_at')->nullable()->after('wants_weekly_digest');
        });

        if ($this->isPostgres()) {
            DB::statement(
                'CREATE INDEX users_weekly_digest_kolejka_idx ON users (weekly_digest_sent_at ASC NULLS FIRST) '
                .'WHERE wants_weekly_digest',
            );
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_weekly_digest_kolejka_idx');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('weekly_digest_sent_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
