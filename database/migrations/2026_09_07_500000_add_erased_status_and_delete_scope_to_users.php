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
 * ROLLBACK
 * `down()` cofa `erased` → `pending_delete`, zdejmuje CHECK-i i kolumnę.
 * Sprawdzone na `kuking_test_usuwanie`: `migrate --force`, `migrate:rollback
 * --step=1`, `migrate --force` przechodzą bez błędu w obie strony.
 * Skutek jest ZNANY i jest nim powrót do usterki opisanej wyżej: teksty
 * wymazanych kont znowu znikną z serwisu (403), bo znów będą na
 * `pending_delete`. Żadne dane nie giną — `delete_scope` traci wyłącznie
 * informację o wyborze zakresu dla kont, które JESZCZE czekają w karencji,
 * a te po rollbacku wracają do zachowania D-018 (teksty zostają), czyli do
 * wariantu mniej nieodwracalnego. Rollback jest więc bezpieczny w tę stronę,
 * w którą trzeba: nie kasuje niczego, czego nie da się odtworzyć.
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
