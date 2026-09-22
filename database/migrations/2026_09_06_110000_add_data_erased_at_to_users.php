<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik trwałego usunięcia danych po karencji (audyt A8).
 *
 * PROBLEM
 * `delete_requested_at` mówi, KIEDY konto zgłoszono do usunięcia, ale nigdzie
 * nie było zapisane, czy dane FAKTYCZNIE zostały już wymazane. Bez tego:
 *
 *  - egzekutor karencji (`kuking:usun-wygasle-konta`) nie miał jak być
 *    idempotentny — każde uruchomienie próbowałoby anonimizować konto od
 *    nowa, nadpisując np. znów wygenerowany losowy e-mail;
 *  - formularz cofnięcia usunięcia konta nie miał jak odróżnić „jeszcze da się
 *    wrócić" od „dane już nie istnieją, cofnięcie tylko wskrzesiłoby pusta
 *    powłokę konta bez treści, którą ono miało".
 *
 * Status konta ZOSTAJE `pending_delete` na zawsze po wykonaniu — nie
 * wprowadzamy nowej wartości statusu (i nie ruszamy `users_status_check`),
 * bo z punktu widzenia logowania i moderacji nic się nie zmienia: konto
 * i tak nie loguje się od momentu zgłoszenia usunięcia. `data_erased_at`
 * to jedyna nowa informacja: czy karencja już się WYKONAŁA.
 *
 * DLACZEGO CHECK, A NIE SAMA WALIDACJA W PHP (AGENTS.md §6)
 * Dane raz wymazane nie mogą „ożyć" przez pomyłkę w innym miejscu kodu —
 * kolumna ma sens wyłącznie przy koncie oznaczonym do usunięcia.
 *
 * ROLLBACK — POPRAWIONY 12 września 2026 (D-088)
 * Stało tu: „Konta, którym dane już wymazano, ZOSTAJĄ wymazane (…) Same konta
 * nie zmieniają zachowania: nadal nie da się ich zalogować". Pierwsze zdanie
 * jest prawdziwe — i jest właśnie powodem, dla którego drugie jest fałszywe.
 * Dane zostają wymazane, ale ŚLAD po tym, że je wymazano, ginie razem
 * z kolumną. Serwis przestaje wiedzieć o czymś, co się naprawdę wydarzyło
 * i czego nie da się odwrócić.
 *
 * `down()` prawie nigdy nie występuje sam: po nim idzie kolejny `migrate` —
 * `migrate:refresh` w CI albo awaryjny rollback WDROŻENIA. Kolumna wraca,
 * CHECK wraca, indeks wraca, żaden wiersz nie ginie — a znacznik jest już
 * NULL-em, czyli mówi „tego konta jeszcze nie wymazano".
 *
 * ZMIERZONE NA PRAWDZIWEJ BAZIE, PRZED TĄ POPRAWKĄ (nie w teorii), cyklem
 * `migrate:rollback` → `migrate`:
 *
 *     PRZED:    status=erased          data_erased_at=2026-09-11
 *     PO CYKLU: status=pending_delete  data_erased_at=NULL
 *
 * Po ludzku: **konto, którego dane bezpowrotnie wymazano, wraca do stanu
 * „czeka w karencji" — czyli do stanu, z którego wolno je wskrzesić.**
 * Trzy miejsca w kodzie czytają wtedy fałsz:
 *
 *  - `CancelAccountDeletion` odmawia cofnięcia usunięcia wyłącznie przy
 *    `data_erased_at !== null || isErased()`. Po cyklu oba są fałszem, więc
 *    przywróci konto, po którym nie ma już ani e-maila, ani treści — pustą
 *    powłokę, przed którą ostrzega sekcja „PROBLEM" wyżej;
 *  - `PurgeExpiredAccountDeletions` ma pierwszą kolejkę na
 *    `whereNull('data_erased_at')`, więc weźmie to konto ponownie, jakby nic
 *    się nie stało — a idempotencja egzekutora była całym powodem istnienia
 *    tej kolumny;
 *  - `2026_09_07_500000_add_erased_status_and_delete_scope_to_users` przenosi
 *    na status `erased` wyłącznie konta z niepustym `data_erased_at`. Po
 *    cyklu żadne tam nie trafi, więc zanonimizowany tekst tych osób znów
 *    zniknie z serwisu — dokładnie ta usterka, którą naprawiło D-022.
 *
 * Dlatego `down()` ODMAWIA, gdy w bazie jest choć jedno konto z wykonanym
 * wymazaniem. Odmowa jest WĄSKA: konta zdrowe i te, które dopiero czekają
 * w karencji (`data_erased_at IS NULL`), jej nie wywołują, więc na świeżej
 * bazie i na stagingu rollback przechodzi bez pytania. Furtka
 * `KUKING_ROLLBACK_KASUJ_ZNACZNIKI_WYMAZANIA` istnieje dlatego, że tego
 * znacznika — inaczej niż terminu kary — nie da się „przeczekać": raz
 * ustawiony zostaje na zawsze, a strażnik bez furtki zablokowałby wycofanie
 * na zawsze, co jest błędem tej samej wagi w drugą stronę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('data_erased_at')->nullable()->after('delete_requested_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_data_erased_at_check
            CHECK (data_erased_at IS NULL OR status = 'pending_delete')
        SQL);

        // Indeks częściowy: egzekutor karencji pyta wyłącznie o konta
        // `pending_delete` bez wykonanej jeszcze anonimizacji. Bez WHERE
        // indeks obejmowałby wszystkie konta, z których zdecydowana
        // większość ma tu NULL.
        DB::statement(<<<'SQL'
            CREATE INDEX users_pending_erase_idx
            ON users (delete_requested_at)
            WHERE status = 'pending_delete' AND data_erased_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // STRAŻNIK PRZED ZAPOMNIENIEM WYKONANEGO WYMAZANIA (D-088).
        // MUSI stać PRZED każdą operacją niżej — sprawdzenie po zdjęciu
        // kolumny chroniłoby tylko komunikat, nie dane.
        $wymazanych = DB::table('users')->whereNotNull('data_erased_at')->count();

        if ($wymazanych > 0 && ! $this->wolnoSkasowacZnacznikiWymazania()) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „jest 1 kont"
            // to nie polszczyzna, a jedno konto jest stanem prawdopodobniejszym
            // niż pięć. Mianownik przed dwukropkiem nie odmienia się wcale,
            // więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Liczba kont z wykonanym wymazaniem danych (data_erased_at) w tabeli `users`: '
                .$wymazanych.".\n\n"
                ."CZYM TO GROZI\n"
                .'Dane tych osób są skasowane bezpowrotnie, a ta kolumna jest JEDYNYM śladem, że to '
                .'się stało. Stary schemat jej nie ma, więc po ponownym `migrate` wróci pusta — '
                .'i serwis uzna te konta za „czekające w karencji". `CancelAccountDeletion` pozwoli '
                .'wtedy wskrzesić pustą powłokę konta, `kuking:usun-wygasle-konta` weźmie je do '
                .'anonimizacji po raz drugi, a zanonimizowany tekst tych osób zniknie z serwisu '
                ."(odwrócenie D-022).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'  1. jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '
                .'cofaj TEJ migracji — kod sprzed niej nie zna kolumny `data_erased_at` i działa '
                ."bez niej bez zmian;\n"
                ."  2. jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz znaczniki PRZED cofnięciem:\n"
                ."       \\copy (SELECT id, data_erased_at FROM users WHERE data_erased_at IS NOT NULL)\n"
                ."       TO 'wymazane.csv' CSV HEADER\n"
                .'     i odtwórz je tym samym `UPDATE` po powrocie na tę wersję schematu, ZANIM '
                ."zadanie `kuking:usun-wygasle-konta` przejdzie choć raz.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ STRACIĆ TE ZNACZNIKI\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_ZNACZNIKI_WYMAZANIA=true '
                .'php artisan migrate:rollback',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_pending_erase_idx');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_data_erased_at_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('data_erased_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    private function wolnoSkasowacZnacznikiWymazania(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_ZNACZNIKI_WYMAZANIA'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
};
