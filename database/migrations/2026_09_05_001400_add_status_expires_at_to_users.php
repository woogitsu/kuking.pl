<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Termin wygaśnięcia kary (issue #40).
 *
 * PROBLEM
 * `docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni"),
 * ale w bazie nie było gdzie zapisać, kiedy kara mija. Przy jednym moderatorze
 * (D-012, zamknięta alfa) nikt tego nie odklika ręcznie po tygodniu — więc
 * KAŻDA blokada czasowa stawała się w praktyce trwała. Playbook obiecywał coś,
 * czego system nie umiał zrobić.
 *
 * Trzy niezależne projekty — Discourse, Pixelfed, Fresns — trzymają to jako
 * datę w kolumnie, nie jako zadanie dla człowieka.
 * Źródło: `docs/research/repos/discourse-discourse.md`.
 *
 * DLACZEGO CHECK, A NIE SAMA WALIDACJA W PHP
 * `AGENTS.md` §6: ograniczenie ma być w bazie, bo walidator obchodzi drugi
 * endpoint. Tutaj chodzi o konkretną niespójność: konto `active` albo `banned`
 * z ustawionym terminem wygaśnięcia jest bez sensu i byłoby cichą pułapką dla
 * zadania przywracającego dostęp. Baza tego po prostu nie przyjmie.
 *
 * Termin dotyczy WYŁĄCZNIE statusu `suspended`:
 * - `banned` jest z definicji bezterminowy (odwołanie idzie przez #10, nie
 *   przez zegar),
 * - `pending_delete` ma własny licznik (`delete_requested_at`),
 * - `active` nie jest karą.
 *
 * Zawieszenie BEZ terminu nadal jest możliwe (kolumna zostaje NULL) — to jest
 * zawieszenie do decyzji człowieka.
 *
 * ROLLBACK — POPRAWIONY 12 września 2026 (D-088), BO POPRZEDNI AKAPIT
 * PRZECZYŁ WŁASNEJ SEKCJI „PROBLEM"
 * Stało tu: „Bezpieczny i bezstratny dla schematu (…) żadne konto nie zmienia
 * przez to statusu i nikt nie traci dostępu. Konta zawieszone pozostają
 * zawieszone do ręcznej decyzji moderatora". Każde z tych zdań jest prawdziwe
 * o WIERSZU i fałszywe o CZŁOWIEKU — a ostatnie jest dokładnie tym stanem,
 * który sekcja „PROBLEM" wyżej nazywa usterką: przy jednym moderatorze
 * (D-012) nikt kary nie odklikuje ręcznie po tygodniu, więc **kara bez
 * terminu jest karą bezterminową**.
 *
 * `down()` prawie nigdy nie występuje sam: po nim idzie kolejny `migrate` —
 * `migrate:refresh` w CI albo awaryjny rollback WDROŻENIA, który pociąga bazę
 * za sobą. Kolumna wraca, CHECK wraca, indeks wraca, żaden wiersz nie ginie,
 * więc nie ma błędu do zauważenia — a termin jest już NULL-em.
 *
 * ZMIERZONE NA PRAWDZIWEJ BAZIE, PRZED TĄ POPRAWKĄ (nie w teorii), cyklem
 * `migrate:rollback` → `migrate`:
 *
 *     PRZED:    status=suspended  status_expires_at=2026-09-19
 *     PO CYKLU: status=suspended  status_expires_at=NULL
 *
 * Po ludzku: **zawieszenie na siedem dni zamienia się w zawieszenie na
 * zawsze, bez jednego komunikatu.** Trzy miejsca w kodzie przestają widzieć
 * koniec kary: `User::punishmentHasExpired()` (wymaga niepustego terminu),
 * `RestoreExpiredSuspensions` (`whereNotNull('status_expires_at')`) i
 * `EnsureAccountIsActive::komunikatZawieszenia()`, który przy NULL-u pisze
 * człowiekowi zdanie bez daty — czyli nie mówi mu już, kiedy wróci.
 *
 * Dlaczego to nie jest ten sam przypadek co `theme` i `posts.display_mode`,
 * pominięte w D-088 świadomie i słusznie: to nie jest preferencja wygody,
 * tylko DŁUGOŚĆ KARY nałożonej przez moderatora. Rollback nie przywraca tu
 * stanu sprzed decyzji — przywraca stan GROŹNIEJSZY niż decyzja, a D-088
 * zabrania tego w pierwszym zdaniu.
 *
 * Dlatego `down()` ODMAWIA, gdy w bazie jest choć jedna kara z terminem.
 * Odmowa jest WĄSKA: konta bez terminu (zawieszenie „do decyzji człowieka",
 * bany, konta zdrowe) jej nie wywołują, więc na świeżej bazie i na stagingu
 * rollback przechodzi bez pytania. Zablokowanie rollbacku na zawsze byłoby
 * błędem tej samej wagi w drugą stronę — i dlatego jest jeszcze furtka
 * `KUKING_ROLLBACK_KASUJ_TERMINY_KAR`, a przy karach, które i tak już minęły,
 * wystarczy uruchomić `kuking:zdejmij-wygasle-kary`: czyści terminy
 * razem ze statusem i odmowa znika sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('status_expires_at')->nullable()->after('delete_requested_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_status_expires_at_check
            CHECK (status_expires_at IS NULL OR status = 'suspended')
        SQL);

        // Indeks częściowy: zadanie w harmonogramie pyta wyłącznie o zawieszenia
        // z minionym terminem. Bez `WHERE` indeks obejmowałby wszystkie konta,
        // z których zdecydowana większość ma tu NULL.
        DB::statement(<<<'SQL'
            CREATE INDEX users_status_expires_at_idx
            ON users (status_expires_at)
            WHERE status_expires_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // STRAŻNIK PRZED ZAMIANĄ KARY CZASOWEJ W BEZTERMINOWĄ (D-088).
        // MUSI stać PRZED każdą operacją niżej: sprawdzenie po zdjęciu kolumny
        // chroniłoby tylko komunikat, nie dane (ten sam błąd kolejności,
        // którego pilnuje `CofniecieMigracjiNieKasujeZeszytowTest`).
        //
        // `DB::table()` (query builder), nie surowe SQL: `dropColumn` niżej
        // wykonuje się bezwarunkowo, na każdym sterowniku, więc sprawdzenie
        // też musi działać poza `isPostgres()`.
        $zTerminem = DB::table('users')->whereNotNull('status_expires_at')->count();

        if ($zTerminem > 0 && ! $this->wolnoSkasowacTerminyKar()) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „jest 1 kar"
            // to nie polszczyzna, a jedna kara jest stanem prawdopodobniejszym
            // niż pięć. Mianownik przed dwukropkiem nie odmienia się wcale,
            // więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Liczba zawieszeń z zapisanym terminem końca w tabeli `users`: '.$zTerminem.".\n\n"
                ."CZYM TO GROZI\n"
                .'Stary schemat (sprzed tej migracji) nie ma tej kolumny wcale, więc po ponownym '
                .'`migrate` wróci ona pusta, a status `suspended` zostanie. Zawieszenie na siedem dni '
                .'zamieni się w zawieszenie na zawsze: `User::punishmentHasExpired()` nie ma czego '
                .'porównać, `kuking:zdejmij-wygasle-kary` tych kont nie widzi (pyta o niepusty termin), '
                .'a ekran zawieszenia przestaje pisać człowiekowi, kiedy wróci. Nikt nie dostanie '
                ."błędu — kara po prostu się nie skończy.\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'  1. jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '
                .'cofaj TEJ migracji — kod sprzed niej nie zna kolumny `status_expires_at` i działa '
                ."bez niej bez zmian;\n"
                .'  2. jeśli te kary i tak już minęły, uruchom `php artisan kuking:zdejmij-wygasle-kary` '
                ."— zdejmie je razem z terminem i odmowa zniknie sama;\n"
                ."  3. jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz terminy PRZED cofnięciem:\n"
                ."       SELECT id, email, status_expires_at FROM users WHERE status_expires_at IS NOT NULL;\n"
                .'     i odtwórz je tym samym `UPDATE` po powrocie na tę wersję schematu, ZANIM '
                ."ktokolwiek z tej listy spróbuje coś opublikować.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ STRACIĆ TE TERMINY\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_TERMINY_KAR=true '
                .'php artisan migrate:rollback',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_status_expires_at_idx');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_expires_at_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('status_expires_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    private function wolnoSkasowacTerminyKar(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_TERMINY_KAR'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
};
