<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAN KOŃCOWY KONTA (`erased`) I ZAKRES USUNIĘCIA WYBRANY PRZEZ CZŁOWIEKA
 * (`delete_scope`) — decyzja D-022, rozszerza D-018.
 *
 * PROBLEM 1: OBIETNICA BEZ POKRYCIA
 * Migracja `..._add_data_erased_at_to_users` napisała wprost: „Status konta
 * ZOSTAJE `pending_delete` na zawsze po wykonaniu — nie wprowadzamy nowej
 * wartości statusu, bo z punktu widzenia logowania i moderacji nic się nie
 * zmienia". Pierwsza część tego zdania jest prawdziwa, druga nie: na
 * `pending_delete` stoi nie tylko logowanie, ale też `User::jestDostepnyJakoAutor()`
 * i sześć Policy. Zmierzone (`UsunieteKontoTresciZostajaWidoczneTest`, przed
 * poprawką): przepis 403, wpis 403, profil 403, przepis wypadał z cudzego
 * zeszytu, komentarz znikał z cudzego wątku.
 *
 * Czyli D-018 obiecało „tekst zostaje zanonimizowany", a serwis go ukrywał.
 * `pending_delete` i „dane wymazane" to DWA RÓŻNE STANY i muszą być dwiema
 * różnymi wartościami:
 *
 *  - `pending_delete` — trwa 30-dniowa karencja. Konto i jego treści są
 *    schowane, bo człowiek poprosił o usunięcie i to jeszcze da się cofnąć.
 *  - `erased` — karencja wykonana. Konta nie da się zalogować ani odzyskać,
 *    ale zanonimizowany tekst JEST widoczny (o ile człowiek nie wybrał
 *    inaczej — patrz `delete_scope`).
 *
 * PROBLEM 2: ZAKRES WYBIERA CZŁOWIEK, A WYBÓR TRZEBA ZAPAMIĘTAĆ
 * Między zgłoszeniem a egzekucją mija 30 dni. Wybór musi więc być ZAPISANY
 * przy żądaniu, a nie odczytany z ekranu w chwili wykonania — tego ekranu
 * może już wtedy nie być w tej formie (D-022, punkt 2).
 *
 * DLACZEGO CHECK-I, A NIE SAMA WALIDACJA W PHP (AGENTS.md §6)
 * Dwa niezmienniki, których nie wolno złamać z żadnego miejsca w kodzie:
 *
 *  1. `(data_erased_at IS NOT NULL) = (status = 'erased')` — RÓWNOWAŻNOŚĆ,
 *     nie implikacja. W jedną stronę: konto z wymazanymi danymi nie może
 *     udawać żywego. W drugą: nie da się postawić statusu `erased` bez
 *     faktycznego wymazania, czyli nie da się „odblokować" widoczności
 *     treści konta, którego danych nikt nie tknął.
 *  2. `delete_scope IN ('minimum','everything')` — słownik wartości w bazie,
 *     nie tylko w PHP.
 *
 * TRZECIEGO CHECK-A („konto w usuwaniu MUSI mieć zapisany zakres") tu
 * ŚWIADOMIE NIE MA, choć był napisany i działał. Powód: `NULL` w tej kolumnie
 * ma już jednoznaczne i BEZPIECZNE znaczenie — `User::chceUsunacTresci()`
 * czyta go jako `minimum`, czyli „nie kasuj tekstów". Wymuszanie wartości
 * dawałoby więc twardy błąd bazy tam, gdzie zachowanie i tak jest poprawne
 * i mniej nieodwracalne, a jedyną drogą do statusu `pending_delete`
 * w kodzie produkcyjnym jest `markForDeletion()`, które zakres ustawia
 * zawsze (`status` jest poza `$fillable`, AGENTS.md §7).
 *
 * MIGRACJA DANYCH ISTNIEJĄCYCH
 * Konta, którym dane już wymazano (`data_erased_at IS NOT NULL`), przechodzą
 * na `erased` — i to jest moment, w którym ich zanonimizowany tekst wraca na
 * serwis, zgodnie z tym, co D-018 obiecało od początku. Zakres dostają
 * `minimum`, bo to jedyny, jaki wtedy istniał.
 *
 * ROLLBACK — POPRAWIONY PO #287 (D-088), TA SAMA CHOROBA CO DB2
 * Ten akapit twierdził wcześniej „żadne dane nie giną — `delete_scope` traci
 * wyłącznie informację o wyborze zakresu dla kont, które JESZCZE czekają
 * w karencji". To było prawdziwe o WIERSZACH i fałszywe o CZŁOWIEKU: kolumna
 * jest `nullable`, więc `dropColumn` w `down()` faktycznie nic nie kasowało
 * z widoku bazy — ale `up()` wyżej BACKFILLUJE `NULL` jako `minimum`. Cykl
 * `migrate` → `migrate:rollback` → `migrate` (dokładnie to, co robi
 * `migrate:refresh` w CI, i dokładnie to, co robi awaryjny rollback
 * WDROŻENIA, nie tylko bazy) więc PO CICHU zamieniał wybór „usuń wszystko"
 * (`everything`) na „usuń minimum" (`minimum`) — bo `minimum` jest jedyną
 * wartością, jaką backfill `up()` umie nadać. Człowiek, który poprosił
 * o usunięcie WSZYSTKICH swoich treści, dostawał po cichu odwrotność swojej
 * decyzji, zrealizowaną później przez `EraseAccountData::chceUsunacTresci()`
 * — bez błędu, bez ostrzeżenia, z poprawną kolumną i poprawną wartością ze
 * słownika. Sprawdzone NA PRAWDZIWEJ BAZIE (nie w teorii): konto z
 * `delete_scope = 'everything'` po `migrate:rollback` + `migrate` miało
 * `delete_scope = 'minimum'`.
 *
 * Naprawa idzie za wariantem 2 z #287: `down()` teraz ODMAWIA, gdy w bazie
 * jest choć jedno konto z `delete_scope = 'everything'` — zamiast zgadywać,
 * czego chciał człowiek. Wzorzec jest ten sam co w
 * `2026_09_10_400100_one_active_data_export_per_user` (odmowa z konkretną
 * instrukcją, nie cichy `DELETE`/`UPDATE`) i w
 * `2026_09_07_800000_appeals_open_to_reporters` (odmowa, gdy stary schemat
 * nie ma jak pomieścić tego, co jest w nowym). Na świeżej bazie, w której
 * nikt nie wybrał `everything`, `down()` przechodzi bez pytania — inaczej
 * „naprawą" byłoby zablokowanie rollbacku na zawsze, co jest błędem
 * dokładnie tej samej wagi (patrz `CofniecieMigracjiNieKasujeZeszytowTest`,
 * ten sam wzorzec kontroli).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('delete_scope', 20)->nullable()->after('data_erased_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        // 1. Najpierw słownik statusów, bo bez `erased` w CHECK-u nie da się
        //    przenieść ani jednego wiersza.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_status_check
            CHECK (status IN ('active','suspended','banned','pending_delete','erased'))
        SQL);

        // 2. Stary CHECK wiązał `data_erased_at` z `pending_delete` — musi
        //    zniknąć PRZED przeniesieniem wierszy.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_data_erased_at_check');

        DB::statement(<<<'SQL'
            UPDATE users SET status = 'erased'
            WHERE status = 'pending_delete' AND data_erased_at IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE users SET delete_scope = 'minimum'
            WHERE status IN ('pending_delete','erased') AND delete_scope IS NULL
        SQL);

        // 3. Niezmienniki dopiero po backfillu.
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_data_erased_at_check
            CHECK ((data_erased_at IS NOT NULL) = (status = 'erased'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_delete_scope_check
            CHECK (delete_scope IS NULL OR delete_scope IN ('minimum','everything'))
        SQL);
    }

    public function down(): void
    {
        // STRAŻNIK PRZED CICHĄ PODMIANĄ WYBORU CZŁOWIEKA (#287, D-088).
        // MUSI stać przed każdą operacją niżej — sprawdzenie po fakcie
        // chroniłoby tylko komunikat, nie dane (ten sam błąd kolejności,
        // którego pilnuje `CofniecieMigracjiNieKasujeZeszytowTest`).
        //
        // `DB::table()` (query builder), nie surowe SQL: to sprawdzenie ma
        // działać na każdym sterowniku, nie tylko pod `isPostgres()` niżej —
        // `dropColumn` na końcu tej metody i tak wykonuje się bezwarunkowo.
        $zEverything = DB::table('users')->where('delete_scope', 'everything')->count();

        if ($zEverything > 0) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „jest 1 kont"
            // to nie polszczyzna, a jedno konto jest stanem
            // prawdopodobniejszym niż pięć. Mianownik przed dwukropkiem nie
            // odmienia się wcale, więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                "Liczba kont z delete_scope = 'everything' w tabeli `users`: ".$zEverything.'. '.
                'Człowiek poprosił o usunięcie WSZYSTKICH swoich treści, nie tylko danych '.
                'osobowych. Stary schemat (sprzed tej migracji) nie ma tej kolumny wcale: gdyby '.
                'cofnięcie przeszło, kolejny `migrate` odtworzyłby ją jako `minimum` — bo to jedyna '.
                'wartość, jaką backfill wyżej umie nadać. Człowiek dostałby po cichu ODWROTNOŚĆ '.
                'swojej decyzji (#287, ta sama choroba co DB2 w '.
                '`2026_09_07_400000_default_weekly_digest_to_off.php`). Migracja odmawia, zamiast '.
                "zgadywać.\n\n".
                "CO ZROBIĆ:\n".
                '  - jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '.
                'cofaj TEJ migracji — kod sprzed niej nie zna kolumny `delete_scope` i działa z nią '.
                'bez zmian (docs/research/audyt-2026-09-10/26_MIGRACJE_ROLLBACK_I_BEZPIECZNE_WDROZENIA.md: '.
                "rollback obrazu i rollback bazy to dwie różne decyzje);\n".
                "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz wartości PRZED cofnięciem:\n".
                "      SELECT id, status, delete_scope FROM users WHERE delete_scope = 'everything';\n".
                '    a po powrocie na tę wersję schematu odtwórz je tym samym `UPDATE`, zanim '.
                'jakiekolwiek konto z tej listy zostanie przetworzone przez `kuking:usun-wygasle-konta` '.
                '(harmonogram w `routes/console.php`, chodzi codziennie).',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_delete_scope_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_data_erased_at_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

            DB::statement("UPDATE users SET status = 'pending_delete' WHERE status = 'erased'");

            DB::statement(<<<'SQL'
                ALTER TABLE users
                ADD CONSTRAINT users_status_check
                CHECK (status IN ('active','suspended','banned','pending_delete'))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE users
                ADD CONSTRAINT users_data_erased_at_check
                CHECK (data_erased_at IS NULL OR status = 'pending_delete')
            SQL);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('delete_scope');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
