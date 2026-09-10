<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odpowiedzi operatora na wiadomości z „Napisz do nas" (D-058).
 *
 * PO CO OSOBNA TABELA, A NIE TRZY KOLUMNY W `contact_messages`
 * Bo odpowiedź nie jest jedna. Moderator pisze „sprawdzamy", a dwa dni
 * później „naprawione" — i to są dwa listy, oba wysłane, oba warte
 * pokazania. Trzy kolumny na wierszu wiadomości zmusiłyby do wyboru:
 * albo druga odpowiedź nadpisuje pierwszą (znika ślad tego, co naprawdę
 * wyszło do człowieka — czyli dokładnie to, czego ten ekran miał zacząć
 * pilnować), albo druga odpowiedź jest niemożliwa.
 *
 * Drugi powód jest twardszy: KAŻDY LIST MA WŁASNY STAN WYSYŁKI. Pierwszy
 * mógł się nie udać, drugi wyjść. Jedna kolumna `status` na wiadomość nie
 * ma jak tego opowiedzieć, a „wysłano" postawione nad nieudaną próbą jest
 * gorsze niż brak funkcji (issue #234: list, którego EmailLabs nie
 * przyjmie, przepada po ~6 minutach i nikt się o tym nie dowiaduje).
 *
 * CZEGO TU CELOWO NIE MA: ADRESU, NA KTÓRY POSZŁO.
 * Adres do odpowiedzi jest już w bazie raz — w `contact_messages.contact_email`
 * (gość) albo na koncie (`users.email`), i wskazuje go
 * `ContactMessage::adresDoOdpowiedzi()`. Skopiowanie go tutaj byłoby TRZECIM
 * miejscem z tą samą daną osobową, przeżywałoby anonimizację konta
 * (`EraseAccountData` nie tyka tej tabeli, bo kont się nie kasuje, tylko
 * anonimizuje) i zamieniłoby wiersz techniczny w mały, niezależny zbiór
 * adresów e-mail. Ta sama zasada minimalizacji, dla której `contact_email`
 * jest `NULL` u zalogowanego.
 *
 * `contact_message_id` MA KASKADĘ, i to jest tu wymóg RODO, nie wygoda.
 * Retencja (`kuking:sprzataj-wiadomosci`, 12 miesięcy od załatwienia) robi
 * masowy `DELETE` na `contact_messages`. Bez `ON DELETE CASCADE` odpowiedzi
 * zostałyby w bazie jako sieroty — treść napisana o konkretnej sprawie
 * konkretnego człowieka, bez wiadomości, do której należała, i bez niczego,
 * co by ją kiedykolwiek usunęło. Kaskada w bazie, nie w PHP: sprzątanie
 * omija model (`Model::delete()` nie jest wołane), więc `deleting` na
 * modelu byłby obietnicą, której nikt nie dotrzyma.
 *
 * ROLLBACK
 * `down()` kasuje tabelę i nie rusza `contact_messages` ani niczego innego,
 * więc cofnięcie jest bezpieczne dla reszty schematu — znika formularz
 * odpowiedzi w panelu i wraca stan sprzed tej zmiany (`mailto:` w karcie
 * wiadomości, który zostaje niezależnie od tej migracji). Strata jest
 * jednak NIEODWRACALNA: w środku leżą listy, które naprawdę poszły do
 * ludzi, i tylko one mówią, co komu odpisano. Przed cofnięciem na czymkolwiek
 * z prawdziwym ruchem:
 *
 *     pg_dump --data-only --table=contact_message_replies > odpowiedzi.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_message_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // KASKADA — patrz nagłówek. Odpowiedź nie ma życia bez wiadomości.
            $table->foreignUuid('contact_message_id')
                ->constrained('contact_messages')
                ->cascadeOnDelete();

            // Kto odpisał. `nullOnDelete()`, bo konto moderatora może zniknąć
            // (albo zostać zanonimizowane), a fakt wysłania listu zostaje —
            // widok pokazuje wtedy „obsługa Kuking" zamiast nazwy.
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Treść listu, dokładnie ta, którą dostał człowiek. `text`, nie
            // `string`: odpowiedź na „nie mogę wgrać zdjęcia" bywa dłuższa
            // niż sama skarga. Górną granicę (5000 znaków, tyle samo co
            // wiadomość) pilnuje walidacja; w bazie stoi CHECK na pustkę.
            $table->text('body');

            // STAN WYSYŁKI — jedyne pole, dla którego ta tabela istnieje
            // w takim kształcie. `w_toku` zapisujemy PRZED wysłaniem, żeby
            // przerwanie procesu w połowie zostawiło na ekranie „nie wiadomo,
            // czy wyszło", a nie ciszę.
            $table->string('status', 20)->default('w_toku');

            // Kiedy dostawca potwierdził przyjęcie. `NULL`, dopóki nie
            // potwierdził — więc to jest jedyna prawdziwa odpowiedź na
            // pytanie „czy ten list wyszedł".
            $table->timestampTz('sent_at')->nullable();

            // Powód porażki, przepuszczony przez redakcję adresów i kluczy
            // (`WyslijOdpowiedzNaWiadomosc::bezpiecznyPowod()`). Ma
            // odpowiadać moderatorowi na pytanie „co teraz zrobić", nie
            // przechowywać cudzych danych.
            $table->string('error', 500)->nullable();

            $table->timestampsTz();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE contact_message_replies ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE contact_message_replies ADD CONSTRAINT contact_message_replies_status_check '
            ."CHECK (status IN ('w_toku','wyslana','nieudana'))",
        );

        // Pusta odpowiedź nie jest odpowiedzią. Walidacja ma to samo
        // `required`, ale to jedyna kolumna, dla której „zapisało się, tylko
        // puste" byłoby awarią niewidoczną z zewnątrz: człowiek dostałby list
        // bez treści, a panel pokazałby zielone „wysłano".
        DB::statement(
            'ALTER TABLE contact_message_replies ADD CONSTRAINT contact_message_replies_body_not_blank '
            ."CHECK (btrim(body) <> '')",
        );

        // ZNACZNIK WYSŁANIA TYLKO PRZY STANIE „WYSŁANA", I ODWROTNIE.
        //
        // Ten CHECK jest odpowiednikiem `contact_messages_handled_complete`
        // i broni tej samej rzeczy: wiersza wewnętrznie sprzecznego. „Wysłana"
        // bez `sent_at` znaczyłoby „poszło, ale nie wiadomo kiedy" (czyli
        // dokładnie ta cisza, którą ta funkcja ma usunąć), a `sent_at` przy
        // stanie „nieudana" — „nie poszło, ale mamy godzinę wysłania".
        DB::statement(
            'ALTER TABLE contact_message_replies ADD CONSTRAINT contact_message_replies_sent_complete '
            ."CHECK ((status = 'wyslana' AND sent_at IS NOT NULL) "
            ."OR (status <> 'wyslana' AND sent_at IS NULL))",
        );

        // Historia odpowiedzi pod jedną wiadomością, najstarsza na górze —
        // czyli w kolejności, w jakiej listy wychodziły. `id` rozstrzyga
        // remis na sekundzie (UUID v7 idzie w tę samą stronę co czas), więc
        // porządek jest stabilny między odświeżeniami.
        DB::statement(
            'CREATE INDEX contact_message_replies_message_idx '
            .'ON contact_message_replies (contact_message_id, created_at, id)',
        );
    }

    public function down(): void
    {
        // Indeksy i CHECK-i znikają razem z tabelą.
        Schema::dropIfExists('contact_message_replies');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
