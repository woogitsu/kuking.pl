<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Napisz do nas" — wiadomość od człowieka DO OPERATORA serwisu.
 *
 * CZYM TO NIE JEST, I DLACZEGO NIE MOŻE TRAFIĆ DO `reports`
 * `reports` to kolejka moderacyjna: skarga na CUDZĄ TREŚĆ. Ma cel
 * (`target_type` + `target_id`), powód z zamkniętej listy, decyzję
 * moderatora, prawo do odwołania (DSA art. 20) i 36-miesięczną retencję
 * uzasadnioną tym, że sprawa może wrócić jako spór.
 *
 * Tutaj nie ma ani celu, ani powodu z listy, ani decyzji, od której da się
 * odwołać. To jest zdanie w rodzaju „przycisk Opublikuj nie działa mi na
 * telefonie" albo „przydałaby się większa czcionka w przepisach".
 * Wrzucenie tego do `reports` znaczyłoby, że połowa kolejki moderacyjnej to
 * nie sprawy, a moderator musi zgadywać, na co patrzy — i że opis błędu żyje
 * w bazie trzy lata, bo tyle wynosi retencja SPRAWY.
 *
 * Rozdział jest więc w schemacie, nie tylko w interfejsie. Osobna tabela,
 * osobny ekran w panelu, osobna retencja, osobny limit zapytań.
 *
 * DLACZEGO `status` MA CHECK W BAZIE, A NIE TYLKO W PHP
 * AGENTS.md §6: prawdziwe CHECK-i w bazie, walidacja w PHP jest dodatkiem.
 * `status` ustawia wyłącznie operator (`ContactMessage` trzyma go poza
 * `$fillable` — ta sama zasada, co dla `status` i `role` użytkownika),
 * więc baza jest ostatnim miejscem, w którym da się to jeszcze złapać,
 * gdyby ktoś kiedyś dopisał drugą drogę zapisu.
 *
 * ROLLBACK
 * `down()` kasuje tabelę i to jest strata NIEODWRACALNA — w środku są
 * zdania napisane przez ludzi, których nikt nie odtworzy. Dlatego przed
 * cofnięciem tej migracji na czymkolwiek z prawdziwym ruchem trzeba zrobić
 * zrzut tabeli:
 *
 *     pg_dump --data-only --table=contact_messages > wiadomosci.sql
 *
 * Sama migracja jest bezpieczna do cofnięcia w drugą stronę: nie rusza
 * ŻADNEJ istniejącej tabeli, więc `down()` nie może uszkodzić niczego poza
 * tym, co sama utworzyła. Reszta serwisu działa dalej — znika formularz
 * i ekran w panelu, zostaje adres e-mail w stopce, czyli stan sprzed tej
 * zmiany.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // NULL na dwa różne sposoby i oba są poprawne:
            //  1. wiadomość od GOŚCIA (nie ma konta — patrz komentarz przy
            //     trasie w routes/web.php),
            //  2. wiadomość od kogoś, kto potem usunął konto. Sama wiadomość
            //     zostaje, bo może być w trakcie załatwiania; dane osoby
            //     znikają razem z kontem.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Tożsamość JEDNEGO wysłania formularza (D-027,
            // docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md). Podwójne
            // kliknięcie „Wyślij" ma dać jedną wiadomość, nie dwie —
            // przy jednoosobowej obsłudze każda kopia to praca do wykonania
            // dwa razy.
            $table->uuid('klucz_wyslania')->nullable();

            // Czego dotyczy — trzy kafelki na formularzu. Nie jest to powód
            // moderacyjny i celowo nie ma nic wspólnego z `Report::REASONS`.
            $table->string('kind', 20);

            // Sama wiadomość. `text`, nie `string`: to jest jedyne miejsce
            // w serwisie, gdzie człowiek OPISUJE awarię, a opis awarii bywa
            // długi. Górną granicę (5000 znaków) pilnuje walidacja w PHP —
            // w bazie zostaje CHECK na to, żeby nie dało się zapisać pustki.
            $table->text('message');

            // Adres do odpowiedzi. Dla zalogowanego zostaje NULL — jego
            // adres jest już na koncie i kopiowanie go tutaj byłoby
            // powielaniem danych osobowych bez powodu (RODO, minimalizacja).
            $table->string('contact_email', 255)->nullable();

            // Adres strony, z której człowiek pisał. Przy „coś nie działa"
            // to jest połowa diagnozy. Zapisujemy wyłącznie ŚCIEŻKĘ z
            // naszego serwisu (kontroler przycina resztę) — nie referer
            // z cudzego serwisu i nie parametry zapytania.
            $table->string('page_path', 300)->nullable();

            // Wydanie serwisu w chwili wysłania (`App\Support\Wersja`).
            // Druga połowa diagnozy: „u mnie nie działa" po wdrożeniu, które
            // to zepsuło. To nie jest dana osobowa — to numer naszej wersji.
            $table->string('wydanie', 80)->nullable();

            // Stan obsługi. USTAWIA GO WYŁĄCZNIE OPERATOR — nie ma go
            // w `$fillable` modelu i nie ma pola w formularzu.
            $table->string('status', 20)->default('new');

            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();

            // Notatka operatora, widoczna tylko w panelu.
            $table->string('handler_note', 2000)->nullable();

            $table->timestampsTz();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE contact_messages ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_kind_check '
            ."CHECK (kind IN ('blad','pomysl','inne'))",
        );

        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_status_check '
            ."CHECK (status IN ('new','in_progress','done'))",
        );

        // Pusta wiadomość nie jest wiadomością. Walidacja w PHP ma to samo
        // `required`, ale to jest jedyna kolumna tej tabeli, dla której
        // „zapisało się, tylko puste" byłoby awarią niewidoczną z zewnątrz.
        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_message_not_blank '
            ."CHECK (btrim(message) <> '')",
        );

        // ZAMKNIĘTA WIADOMOŚĆ MA KOMPLET: KTO I KIEDY.
        //
        // `num_nonnulls()` zamiast trzech osobnych warunków — ten sam
        // wzorzec, co w reszcie schematu. Bez tego dałoby się mieć
        // `status = 'done'` bez śladu, kto to zamknął, a wtedy retencja
        // (liczona od `handled_at`) nie miałaby od czego liczyć i wiersz
        // zostawałby w bazie na zawsze.
        DB::statement(
            'ALTER TABLE contact_messages ADD CONSTRAINT contact_messages_handled_complete '
            ."CHECK ((status = 'new' AND num_nonnulls(handled_by, handled_at) = 0) "
            ."OR (status <> 'new' AND num_nonnulls(handled_by, handled_at) = 2))",
        );

        // Kolejka operatora: najstarsze nieobsłużone na górze. `id` jako
        // drugi warunek porządku — UUID v7 rozstrzyga remis na sekundzie
        // w tę samą stronę co czas (ta sama pułapka, co w kolejce zgłoszeń:
        // niestabilny porządek zmienia PODZIAŁ NA STRONY między kliknięciami).
        DB::statement('CREATE INDEX contact_messages_status_created_idx ON contact_messages (status, created_at, id)');

        // Retencja liczy się od `handled_at` — bez tego indeksu nocne
        // sprzątanie skanowałoby całą tabelę.
        DB::statement(
            'CREATE INDEX contact_messages_handled_at_idx ON contact_messages (handled_at) '
            .'WHERE handled_at IS NOT NULL',
        );

        // Jedno wysłanie = jeden wiersz (D-027). Indeks CZĘŚCIOWY, bo
        // `klucz_wyslania` jest `NULL`, gdy wyłącznik awaryjny
        // (`kuking.formularze.klucz_wyslania_wlaczony`) jest zdjęty — a wtedy
        // serwis ma wrócić do zachowania sprzed D-027, nie odmawiać zapisu.
        DB::statement(
            'CREATE UNIQUE INDEX contact_messages_one_per_klucz_wyslania '
            .'ON contact_messages (klucz_wyslania) WHERE klucz_wyslania IS NOT NULL',
        );
    }

    public function down(): void
    {
        // Indeksy i CHECK-i znikają razem z tabelą — `DROP TABLE` zabiera je
        // ze sobą, więc osobne `DROP INDEX` byłoby tu tylko szumem.
        Schema::dropIfExists('contact_messages');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
