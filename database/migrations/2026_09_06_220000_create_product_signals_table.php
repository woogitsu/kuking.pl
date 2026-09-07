<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `product_signals` — sygnały produktowe: dziś dokładnie dwa zdarzenia,
 * `photo_upload_failed` i `search_performed` (issue #115).
 *
 * SKĄD TEN KSZTAŁT TABELI
 * `docs/research/ANALITYKA.md` (przywoływany przez issue #115 po schemat
 * i retencję) ISTNIEJE — ten komentarz twierdził wcześniej, że „nikt go nie
 * zacommitował", i było to nieprawdziwe: leżał na gałęziach `research/*`,
 * niescalony, więc nie było go w drzewie roboczym. Ta migracja powstała
 * z kolumn wypisanych wprost w treści issue #115 i okazała się zgodna
 * z tamtym dokumentem, w tym co do 90 dni retencji (jego §3.5). Wzorcem, który naprawdę istnieje
 * i który ta tabela okrada z rozmachu (celowo), jest `product_events`
 * z `docs/seo/ANALYTICS.md` §7 — tam osobny serwis na wszystkie zdarzenia
 * produktu, tu jedna wąska tabela na cztery pola i dwa zdarzenia.
 *
 * `id` JEST `bigserial`, NIE `uuid` — w przeciwieństwie do encji publicznych
 * (AGENTS.md §6 chce UUID tam, gdzie identyfikator może trafić do adresu czy
 * odpowiedzi API). Wiersz `product_signals` nigdy nie jest adresowany z
 * zewnątrz ani pokazywany człowiekowi — to ten sam przypadek co `audit_log`
 * (patrz `2026_09_05_001000_create_trust_and_safety_tables.php`), stąd ten
 * sam wybór typu klucza.
 *
 * `user_id` NULLABLE Z `ON DELETE SET NULL` — CELOWO, DOKŁADNIE JAK ISSUE
 * MÓWI
 * Usunięcie konta (anonimizacja przez `EraseAccountData`, DEKLARACJA D-018)
 * nie kasuje wiersza z historii metryk — sygnał sam w sobie jest wartościowy
 * bez względu na to, kto go wywołał (ile osób nie może wgrać zdjęcia to
 * dalej ile osób, nawet gdy jedna z nich później usunie konto). Ale ten sam
 * wiersz też NIE MOŻE zostać związany z konkretnym, usuniętym kontem —
 * to byłoby trzymanie odniesienia do osoby dłużej, niż istnieje samo konto.
 * `SET NULL` daje dokładnie tę parę gwarancji: wiersz przeżywa, referencja
 * do człowieka — nie.
 *
 * DWA CHECK-i, NIE JEDEN
 *   - `product_signals_signal_name_check` — zamknięty zbiór nazw zdarzeń,
 *     ten sam wzorzec co `Report::REASONS` / `data_exports.failure_reason`
 *     (`2026_09_06_210000_convert_data_export_failure_reason_to_codes.php`):
 *     liczy się to, czego baza NIE MOGŁA przyjąć, nie to, co akurat sprawdzał
 *     kod aplikacji w chwili przeglądu.
 *   - `product_signals_no_query_text_check` — DRUGA LINIA OBRONY prywatności
 *     (AGENTS.md §7: żadnych PII w danych analitycznych), NIEZALEŻNA od tego,
 *     co dziś pisze `SearchController`. Fraza wyszukiwania jest tekstem
 *     wpisanym przez człowieka, tej samej natury co treść komentarza — a więc
 *     dokładnie tym, czego ta tabela nigdy nie ma prawa nieść. Ten CHECK nie
 *     sprawdza JEDNEJ kolumny: zamyka drogę kluczowi `query_text` w całym
 *     `properties`, więc pomyłka w przyszłym kodzie (np. ktoś doda drugie
 *     zdarzenie i przez nieuwagę wstawi tam frazę) kończy się odrzuconym
 *     INSERT-em, a nie cichym wyciekiem, który ujawni dopiero audyt.
 *
 * INDEKSY
 * Zapytania, jakie się tu zrobi, są dwa: „ile `signal_name` w danym oknie
 * czasu" (dashboard, `docs/seo/ANALYTICS.md` §6 pkt 6 — „upload error rate")
 * i „skasuj wszystko starsze niż N dni" (retencja, komenda
 * `kuking:sprzataj-sygnaly`). Stąd złożony indeks `(signal_name, occurred_at
 * DESC)` pod pierwsze i osobny `(occurred_at)` pod drugie — retencja nie
 * filtruje po `signal_name`, więc złożony indeks jej nie obsłuży.
 *
 * ROLLBACK
 * Bezpieczny bez zastrzeżeń: to są dane telemetryczne, odtwarzalne przez
 * ponowne uruchomienie zdarzeń w produkcji, nie dane, na podstawie których
 * podjęto decyzję (jak w `reports`) — `down()` po prostu kasuje tabelę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signal_name', 60);
            $table->jsonb('properties')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('occurred_at')->useCurrent();
        });

        if ($this->isPostgres()) {
            DB::statement(
                'ALTER TABLE product_signals ADD CONSTRAINT product_signals_signal_name_check '
                ."CHECK (signal_name IN ('photo_upload_failed', 'search_performed'))",
            );

            // Patrz komentarz klasy: to jest DRUGA linia obrony, niezależna
            // od tego, co dziś pisze aplikacja.
            //
            // `jsonb_exists(properties, 'query_text')`, NIE operator `?` —
            // ten sam warunek, ale bez znaku, który PDO (`DB::statement()`
            // idzie przez `PDO::prepare()`) czyta jako placeholder pozycyjny.
            // Z gołym `?` w treści zapytania ta migracja wywracała się na
            // „SQLSTATE[HY093]: Invalid parameter number", zanim CHECK w ogóle
            // powstał.
            DB::statement(
                'ALTER TABLE product_signals ADD CONSTRAINT product_signals_no_query_text_check '
                ."CHECK (NOT jsonb_exists(properties, 'query_text'))",
            );

            DB::statement('CREATE INDEX product_signals_name_time_idx ON product_signals (signal_name, occurred_at DESC)');
            DB::statement('CREATE INDEX product_signals_occurred_idx ON product_signals (occurred_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_signals');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
