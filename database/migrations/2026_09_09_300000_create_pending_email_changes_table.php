<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oczekująca zmiana adresu e-mail (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nowy adres NIE MOŻE trafić do `users.email` w chwili, w której ktoś go
 * wpisał. Gdyby trafiał, wystarczyłaby jedna niezablokowana przeglądarka,
 * żeby przestawić konto na cudzy adres i przejąć je resetem hasła. Adres
 * obowiązuje dopiero po kliknięciu w link wysłany NA NIEGO — a do tego
 * czasu żądanie musi gdzieś poczekać.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNA TABELA, A NIE TRZY KOLUMNY NA `users`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Rozważana była druga droga: `pending_email`, `pending_email_expires_at`
 * (i ewentualnie `pending_email_requested_at`) obok `users.email`. Odpadła
 * z czterech mierzalnych powodów, nie z upodobania:
 *
 *  1. TO NIE JEST CECHA KONTA, TYLKO ŻĄDANIE Z WŁASNYM ŻYCIORYSEM —
 *     powstaje, wygasa, zostaje skasowane albo skonsumowane. Wiersz, który
 *     znika w całości, jest prostszy od trzech kolumn, które trzeba
 *     wyzerować RAZEM — a „razem" jest dokładnie tym, o czym się zapomina
 *     (patrz `users.delete_scope`, gdzie ten sam kształt wymusił CHECK
 *     wiążący dwie kolumny).
 *
 *  2. SPÓJNOŚĆ ZA DARMO. Przy kolumnach trzeba pilnować CHECK-iem, że
 *     `pending_email IS NULL` = `pending_email_expires_at IS NULL`
 *     (`num_nonnulls()`, jak przy `collection_items`). Przy tabeli ten
 *     warunek nie ma jak nie być spełniony: albo wiersz jest, albo go nie ma.
 *
 *  3. `users` JEST NAJGORĘTSZĄ TABELĄ W SERWISIE — czyta ją każde żądanie
 *     zalogowanej osoby. Dokładanie do niej trzech kolumn, z których 99,9%
 *     wierszy ma NULL, jest kosztem płaconym przy KAŻDYM odczycie konta za
 *     rzecz, która dotyczy garstki kont przez kilkanaście godzin w życiu.
 *
 *  4. MINIMALIZACJA DANYCH (RODO). Adres w tej tabeli to dana osobowa,
 *     która ma zniknąć, gdy przestanie być potrzebna. Skasowanie wiersza
 *     jest jednym `DELETE` w komendzie sprzątającej
 *     (`kuking:sprzataj-zmiany-adresu`); wyzerowanie kolumn na `users` to
 *     `UPDATE` na najważniejszej tabeli w bazie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST W SCHEMACIE I DLACZEGO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `user_id` UNIKALNY — JEDNO oczekujące żądanie na konto. Nowe żądanie
 * zastępuje poprzednie (`RequestEmailChange`), więc link z poprzedniego
 * listu natychmiast przestaje działać. To jest własność bezpieczeństwa,
 * nie wygoda: bez niej ktoś, kto raz dorwał się do sesji, mógłby zostawić
 * po sobie pół tuzina ważnych linków „na później".
 *
 * `new_email` BEZ INDEKSU UNIKALNEGO — świadomie. Unikalność adresu
 * pilnuje `users_email_lower_unique` w chwili POTWIERDZENIA. Gdyby
 * unikalność obowiązywała już tutaj, sam formularz odpowiadałby „ten adres
 * jest zajęty" i stałby się wyrocznią „kto ma konto w Kuking" — czyli
 * dokładnie tym wyciekiem, którego issue #195 zabrania. Pełne uzasadnienie
 * przy `App\Domain\Users\Actions\ConfirmEmailChange`.
 *
 * `expires_at` ZAPISANE W WIERSZU, a nie liczone przy odczycie. Dzięki temu
 * sprzątanie pyta o `expires_at < now()` i nie musi odejmować niczego od
 * dzisiejszej daty — nie da się więc powtórzyć pułapki `subMonths()` kontra
 * `subMonthsNoOverflow()` z `App\Domain\Compliance\PrzedawnionePowiadomienia`
 * (A6-04), gdzie przepełnienie daty przesuwało próg na nowsze wiersze
 * i kasowało je przed czasem. Termin jest tu policzony RAZ, w chwili
 * złożenia żądania, i od tego momentu jest faktem, nie wynikiem działania
 * arytmetycznego.
 *
 * CHECK-i w bazie, nie tylko w PHP (AGENTS.md §6):
 *  - `new_email` małymi literami — ta sama reguła co
 *    `users_email_lower_unique`. Mutator `User::email()` normalizuje adres
 *    przy zapisie konta; tutaj normalizuje go `PendingEmailChange`, ale
 *    walidator obchodzi się drugim endpointem, a CHECK nie.
 *  - `expires_at > created_at` — żądanie wygasłe w chwili powstania nie ma
 *    prawa istnieć; byłoby cichą pułapką („wysłaliśmy list", a link martwy).
 *
 * BEZ INDEKSU NA `expires_at`. Tabela mieści najwyżej jeden wiersz na konto
 * i tylko przez kilkanaście godzin — realnie kilkadziesiąt wierszy. Skan
 * sekwencyjny takiej tabeli jest tańszy niż utrzymywanie indeksu przy
 * każdym zapisie. Indeks dokładamy, gdy będzie zmierzony powód (AGENTS.md
 * §3), nie „na przyszłość".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `php artisan migrate:rollback --step=1` — `down()` kasuje całą tabelę.
 * Bezpieczne na produkcji i bezstratne dla KONT: żaden wiersz `users` nie
 * jest przez tę migrację dotykany, żaden adres e-mail nie zmienia się ani
 * w jedną, ani w drugą stronę.
 *
 * Traci się wyłącznie żądania W TRAKCIE — czyli listy wysłane, ale jeszcze
 * niepotwierdzone. Osoba, która akurat czekała na link, po jego kliknięciu
 * zobaczy „to żądanie już nie istnieje, zacznij od nowa" i zacznie od nowa.
 * Nikt nie traci konta, nikt nie zostaje z adresem, którego nie zamawiał.
 *
 * Wycofanie tej migracji BEZ wycofania kodu zostawia jednak ekran
 * `/ustawienia/e-mail` odwołujący się do nieistniejącej tabeli — czyli 500
 * na wejściu. Kolejność wycofywania: najpierw kod, potem migracja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_email_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // ON DELETE CASCADE — wiersz nie ma sensu bez konta. Kont
            // z Kuking się dziś nie kasuje (anonimizuje je `EraseAccountData`,
            // D-022), więc kaskada jest tu drugą linią obrony, nie pierwszą:
            // pierwszą jest jawne skasowanie żądania w tamtej akcji.
            $table->foreignUuid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('new_email', 255);

            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE pending_email_changes
            ADD CONSTRAINT pending_email_changes_new_email_lower_check
            CHECK (new_email = lower(new_email) AND new_email <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pending_email_changes
            ADD CONSTRAINT pending_email_changes_expires_after_created_check
            CHECK (expires_at > created_at)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_email_changes');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
