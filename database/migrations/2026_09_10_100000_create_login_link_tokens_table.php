<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jednorazowy token logowania linkiem e-mail — „magic link" (issue #25).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Link wysłany pocztą JEST hasłem jednorazowym. Musi więc mieć wszystko to,
 * co ma hasło jednorazowe: krótki termin ważności, jedno użycie i zapis
 * w bazie WYŁĄCZNIE w postaci skrótu. Wiersz z tokenem jawnym byłby
 * kompletem kluczy do wszystkich kont, jaki wypada z pierwszego zrzutu bazy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNA TABELA, A NIE KOLUMNY NA `users`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ten sam wywód co przy `pending_email_changes` (D-048) i ta sama odpowiedź:
 *
 *  1. to nie jest cecha konta, tylko ŻĄDANIE Z WŁASNYM ŻYCIORYSEM —
 *     powstaje, wygasa, zostaje zużyte albo unieważnione. Wiersz znikający
 *     w całości jest prostszy niż dwie kolumny, które trzeba wyzerować
 *     RAZEM (a „razem" jest dokładnie tym, o czym się zapomina);
 *  2. spójność za darmo — nie trzeba CHECK-a wiążącego nullowość dwóch
 *     kolumn (`num_nonnulls()`), bo albo wiersz jest, albo go nie ma;
 *  3. `users` czyta KAŻDE żądanie zalogowanej osoby, a te kolumny byłyby
 *     NULL-em w 99,9% wierszy przez 99,9% czasu;
 *  4. minimalizacja danych: unieważnienie to jeden `DELETE`, a nie `UPDATE`
 *     na najgorętszej tabeli w bazie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST W SCHEMACIE I DLACZEGO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `user_id` UNIKALNY — JEDEN ważny link na konto. Kolejna prośba ZASTĘPUJE
 * poprzednią, więc link z wcześniejszego listu przestaje działać w tej samej
 * chwili. To jest własność bezpieczeństwa, nie wygoda: bez niej ktoś, kto raz
 * dorwał się do cudzej skrzynki, zostawiłby sobie pół tuzina ważnych wejść
 * „na później". Ta sama decyzja co przy `pending_email_changes.user_id`
 * i przy `password_reset_tokens` (tam kluczem głównym jest adres e-mail,
 * więc jeden token na adres wychodzi z samego schematu).
 *
 * `token_hash` — **HMAC-SHA256 tokenu, nigdy token**. Kolumna jest UNIKALNA,
 * bo po niej wyszukujemy wiersz przy kliknięciu w link: token z adresu
 * hashujemy tak samo i pytamy o równość. Skrót szybki (a nie bcrypt jak
 * w `password_reset_tokens`) jest tu wyborem świadomym i bezpiecznym:
 * bcrypt istnieje po to, żeby spowolnić zgadywanie wartości o NISKIEJ
 * entropii (hasło człowieka), a tu wartością jest 256 bitów z
 * `random_bytes()`. Zgadywania nie ma czego spowalniać, za to bcrypt
 * uniemożliwiłby wyszukanie wiersza po skrócie — musielibyśmy wstawić do
 * adresu identyfikator wiersza obok tokenu, czyli wynieść do listu i do
 * historii przeglądarki jedną informację więcej bez żadnego zysku.
 * Skrót liczy `App\Support\Skrot::hmac()` — ta sama konstrukcja co przy
 * `audit_log.ip_hash` i przy kluczach limitera logowania.
 *
 * `expires_at` ZAPISANE W WIERSZU, a nie liczone przy odczycie — ten sam
 * powód co w `pending_email_changes`: termin ma być faktem policzonym raz,
 * a nie wynikiem arytmetyki na datach przy każdym sprawdzeniu (pułapka
 * `subMonths()` kontra `subMonthsNoOverflow()`, A6-04).
 *
 * CZEGO W TEJ TABELI NIE MA, ŚWIADOMIE:
 *
 *  - `used_at`. Zużyty link znika (`DELETE`), a nie zostaje oznaczony.
 *    Wiersz po użyciu nie odpowiadałby już na żadne pytanie, którego nie
 *    odpowiada wpis `account.login_link_used` w `audit_log`, a byłby
 *    kolejnym miejscem, w którym trzeba pamiętać o warunku „AND used_at IS
 *    NULL". Zapomnienie o takim warunku znaczy link wielokrotnego użytku,
 *    czyli dokładnie ta usterka, przed którą cała tabela ma bronić.
 *  - adresu IP proszącego ani `user_agent`. Nie ma pytania, na które
 *    musiałyby odpowiedzieć, a byłby to czwarty zbiór adresów IP w bazie
 *    (AGENTS.md §7). Fakt złożenia prośby notuje `audit_log` — z adresem
 *    w skrócie, jak wszystko tam.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `php artisan migrate:rollback --step=1` — `down()` kasuje całą tabelę.
 * Bezpieczne na produkcji i BEZSTRATNE DLA KONT: ta migracja nie dotyka ani
 * jednego wiersza `users`, nie zmienia haseł i nie zamyka logowania hasłem,
 * które przez cały czas jest drogą równoległą (D-056).
 *
 * Traci się wyłącznie linki W DRODZE — wysłane, a jeszcze niekliknięte.
 * Kto kliknie taki link po wycofaniu, przeczyta „ten link już nie działa,
 * poproś o nowy" i poprosi o nowy albo zaloguje się hasłem.
 *
 * Wycofanie migracji BEZ wycofania kodu zostawia trasy `/logowanie/link`
 * odwołujące się do nieistniejącej tabeli, czyli 500 na formularzu
 * publicznym. Kolejność wycofywania: NAJPIERW KOD, POTEM MIGRACJA — a jeśli
 * chodzi tylko o wyłączenie funkcji, migracja nie jest do tego potrzebna
 * wcale: `KUKING_LOGOWANIE_LINKIEM=false` zdejmuje ją bez wdrożenia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_link_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // ON DELETE CASCADE — token nie ma sensu bez konta. Kont się tu
            // nie kasuje (anonimizuje je `EraseAccountData`, D-022), więc
            // kaskada jest drugą linią obrony; pierwszą jest jawne kasowanie
            // tokenów w `User::invalidateSessions()`.
            $table->foreignUuid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // 64 znaki: HMAC-SHA256 zapisany szesnastkowo.
            $table->string('token_hash', 64)->unique();

            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        // CHECK-i w bazie, nie tylko w PHP (AGENTS.md §6). Walidator obchodzi
        // się drugim endpointem, CHECK nie.
        DB::statement(<<<'SQL'
            ALTER TABLE login_link_tokens
            ADD CONSTRAINT login_link_tokens_expires_after_created_check
            CHECK (expires_at > created_at)
        SQL);

        // SKRÓT MA BYĆ SKRÓTEM: 64 znaki szesnastkowe małymi literami.
        //
        // To nie jest ozdoba schematu, tylko bramka na jedyny błąd, którego
        // ta tabela nie ma prawa przeżyć: zapisanie tokenu JAWNIE. Sam token
        // powstaje przez `Str::random(64)` — z wielkimi literami, więc
        // wpisany tu wprost łamie ten warunek i baza go odrzuci. Dlatego
        // token NIE JEST szesnastkowy i nie wolno go na taki zmienić:
        // zabrałoby to CHECK-owi całą wartość.
        DB::statement(<<<'SQL'
            ALTER TABLE login_link_tokens
            ADD CONSTRAINT login_link_tokens_token_hash_format_check
            CHECK (token_hash ~ '^[0-9a-f]{64}$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('login_link_tokens');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
