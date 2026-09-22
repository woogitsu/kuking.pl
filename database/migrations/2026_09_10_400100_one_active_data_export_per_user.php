<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jeden AKTYWNY eksport danych na konto — egzekwowany w bazie (audyt
 * 10.09.2026 ustalenia QUEUE-04 / RACE-05, `docs/DECISIONS.md` D-078).
 *
 * CO BYŁO: `DataSettingsController::requestExport()` robił `exists()` na
 * stanach `queued`/`processing`, a potem OSOBNY `INSERT`. Między tymi dwoma
 * zapytaniami nie było nic — ani transakcji, ani ograniczenia w schemacie.
 * Przy izolacji `read committed` (domyślnej w PostgreSQL) dwa równoległe
 * żądania widzą „nie ma aktywnego eksportu" JEDNOCZEŚNIE i oba wstawiają
 * swój wiersz bez czekania. To jest wstawienie fantomu, a nie konflikt na
 * wierszu, więc `SELECT ... FOR UPDATE` też by tego nie złapał: blokada na
 * zapytaniu, które nie zwróciło żadnego wiersza, nie blokuje niczego —
 * zmierzone i opisane przy `2026_09_07_900000_one_open_report_per_pair`.
 *
 * DLACZEGO TO NIE JEST DROBIAZG. Skutkiem są DWA ciężkie eksporty tego samego
 * konta: `GenerateUserExport` pakuje wszystkie zdjęcia, ma 15 minut limitu
 * czasu i chodzi na kolejce `low` przy jednym workerze. Dwa takie zadania
 * naraz to jedna z najdroższych rzeczy, jakie ten serwis potrafi zrobić, plus
 * dwa e-maile do jednej osoby z tego samego dobowego wiadra 300 listów.
 * A wejściem jest podwójne kliknięcie „Zamów swoje dane" — przy grupie 60+
 * dwuklik jest scenariuszem TYPOWYM, nie skrajnym (`docs/UX_50_PLUS.md`),
 * dokładnie tak samo jak przy formularzach objętych kluczem wysłania
 * (`docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).
 *
 * `exists()` W PHP ZOSTAJE i nie jest tu zbędny — ale ma inną robotę niż
 * indeks. `exists()` daje ŁADNY KOMUNIKAT („już przygotowujemy Twoją
 * paczkę"), indeks daje GWARANCJĘ. AGENTS.md §6: „prawdziwe klucze obce
 * i prawdziwe CHECK-i w bazie — walidacja w PHP jest dodatkiem, nie
 * zamiennikiem". Tutaj było odwrotnie.
 *
 * DLACZEGO INDEKS CZĘŚCIOWY, A NIE ZWYKŁY UNIQUE NA `user_id`
 * Bo inwariant brzmi „jeden AKTYWNY", nie „jeden w historii". Ekran
 * `/ustawienia/twoje-dane` pokazuje pięć ostatnich paczek, a człowiek ma
 * prawo zamówić dane po raz kolejny — RODO art. 15 nie jest jednorazowe.
 * Zwykły UNIQUE zabraniałby drugiego eksportu NA ZAWSZE, czyli zamieniłby
 * usterkę współbieżności na usterkę produktową. Warunek `WHERE status IN
 * ('queued','processing')` znaczy, że wiersz wypada z indeksu w chwili,
 * w której job go domknie (`ready`, `failed`) albo paczka wygaśnie
 * (`expired`) — i następne żądanie znów przechodzi.
 *
 * To jest ten przypadek, w którym PostgreSQL potrafi wyrazić inwariant,
 * a `exists()` w PHP nie potrafi.
 *
 * MIGRACJA ODMAWIA, GDY DUPLIKATY JUŻ SĄ W BAZIE — bo `CREATE UNIQUE INDEX`
 * i tak odbiłby się o dane, tylko komunikatem PostgreSQL, z którego nie
 * wynika, co zrobić. Poniżej jest ten sam wynik z instrukcją. CO WTEDY
 * ZROBIĆ (na produkcji: najpierw `pg_dump` tabeli, potem to):
 *
 *   -- 1. zobacz, o które konta chodzi
 *   SELECT user_id, count(*) FROM data_exports
 *    WHERE status IN ('queued','processing')
 *    GROUP BY user_id HAVING count(*) > 1;
 *
 *   -- 2. zostaw NAJSTARSZY aktywny wiersz na konto, nadmiarowe skasuj
 *   DELETE FROM data_exports d USING (
 *     SELECT user_id, min(created_at) AS pierwszy FROM data_exports
 *      WHERE status IN ('queued','processing') GROUP BY user_id
 *   ) k
 *    WHERE d.user_id = k.user_id
 *      AND d.status IN ('queued','processing')
 *      AND d.created_at > k.pierwszy;
 *
 * KASOWANIE JEST TU BEZPIECZNE i to jest różnica wobec `reports`, gdzie
 * migracja każe rozstrzygać człowiekowi. Trzy powody, wszystkie sprawdzone
 * w kodzie:
 *
 *   1. Wiersz w stanie `queued`/`processing` nie ma jeszcze `object_key`
 *      ani `disk` (stawia je `GenerateUserExport` dopiero przy `ready`),
 *      więc nie ma osieroconego pliku w magazynie.
 *   2. `GenerateUserExport::handle()` zaczyna od `find($this->dataExportId)`
 *      i przy braku wiersza po prostu wraca (`return`) — zadanie z kolejki,
 *      któremu skasowano wiersz, nie wywraca workera.
 *   3. To nie jest sprawa z terminem odpowiedzi jak zgłoszenie z DSA art. 16.
 *      Paczka, która i tak powstaje z tego samego konta, jest bajt w bajt
 *      tą samą paczką — człowiek dostaje swoje dane z pozostawionego wiersza.
 *
 * ILE TO PRAWDOPODOBNE DZIŚ: sprawdzone na `main`, jedyną drogą do
 * `data_exports` jest ten kontroler, a `exists()` łapie zwykłe podwójne
 * kliknięcie (dwa żądania jedno po drugim). Duplikaty wymagają dwóch żądań
 * NAPRAWDĘ równoległych. Migracja nie zakłada, że ich nie ma — założenie
 * „na pewno nie ma" jest właśnie tym, co przewraca migracje na produkcji.
 *
 * ROLLBACK: `DROP INDEX IF EXISTS`, bezstratnie — indeks nie przechowuje
 * niczego, czego nie ma w tabeli, i jego zdjęcie nie kasuje żadnego wiersza.
 * Po cofnięciu wraca stan sprzed zmiany: `exists()` w PHP łapie podwójne
 * kliknięcie, baza nie broni niczego. `down()` nie ma więc czego odmawiać.
 * Kod aplikacji po rollbacku migracji też działa: `catch` na konflikcie
 * unikalności w kontrolerze jest wtedy gałęzią, w którą nic nie wchodzi.
 */
return new class extends Migration
{
    private const INDEKS = 'data_exports_one_active_per_user';

    /**
     * Stany aktywne wypisane WPROST, nie przez stałe z `App\Models\DataExport`.
     * Migracja opisuje schemat z dnia, w którym powstała, i musi dać się
     * odtworzyć od zera także wtedy, gdy model kiedyś zmieni słownik stanów —
     * żadna migracja w tym repozytorium nie importuje klas z `app/`.
     *
     * @var list<string>
     */
    private const STANY_AKTYWNE = ['queued', 'processing'];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $duplikaty = DB::table('data_exports')
            ->selectRaw('user_id, count(*) as ile')
            ->whereIn('status', self::STANY_AKTYWNE)
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplikaty->isNotEmpty()) {
            $opis = $duplikaty
                ->map(fn ($wiersz): string => $wiersz->user_id.' ('.$wiersz->ile.')')
                ->implode('; ');

            throw new RuntimeException(
                'W `data_exports` są już konta z więcej niż jednym aktywnym eksportem: '.$opis.'. '
                .'Indeks by się o nie odbił, więc zatrzymuję się tutaj, zanim wdrożenie stanie w połowie. '
                .'Zostaw NAJSTARSZY aktywny wiersz na konto i skasuj nadmiarowe (gotowy SQL i uzasadnienie, '
                .'dlaczego kasowanie jest tu bezpieczne, są w komentarzu na górze tej migracji), '
                .'a potem uruchom ją ponownie.',
            );
        }

        $stany = "'".implode("','", self::STANY_AKTYWNE)."'";

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEKS.' ON data_exports (user_id) '
            .'WHERE status IN ('.$stany.')',
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
