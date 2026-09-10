<?php

declare(strict_types=1);

use App\Poczta\PowodOdmowy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_failures` — trwały ŚLAD listu, który nie wyszedł (issue #234, D-062).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 10 września 2026 list, którego dostawca nie przyjął, kończył tak:
 * trzy próby (`--tries=3 --backoff=10,60,300`), czyli około sześciu minut,
 * potem wiersz w `failed_jobs` — i cisza. Adresat nie dowiadywał się nigdy,
 * właściciel tylko wtedy, gdy sam z siebie zajrzał w `php artisan
 * queue:failed`. Kolejka była pusta, `/health` zielony, w panelu nic:
 * **awaria wyglądała identycznie jak sukces**, a dotyczyło to potwierdzeń
 * rejestracji, przypomnień hasła i logowania linkiem — czyli sytuacji,
 * w których człowiek stoi przed ekranem i czeka.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNA TABELA, A NIE `failed_jobs`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo `failed_jobs` odpowiada na inne pytanie i nie da się go o to nasze
 * zapytać:
 *
 *  1. `failed_jobs` trzyma WSZYSTKIE nieudane zadania — przetwarzanie zdjęć,
 *     eksporty, analizy treści. „Czy przepadł jakiś list" to w tej tabeli
 *     zapytanie po treści zserializowanego payloadu, czyli po tekście;
 *  2. nie ma tam ŻADNEGO miejsca na to, co wiemy o odmowie: czy to wyczerpany
 *     limit dobowy (przewidywalne, przejdzie po północy), czy zły adres
 *     (nie przejdzie nigdy). A to jest cała różnica dla człowieka, który ma
 *     coś z tym zrobić;
 *  3. `failed_jobs` czyści się `queue:flush` i `queue:retry`, więc ślad
 *     ginie razem z ponowieniem — a pytanie „czy 12 września listy
 *     przepadały" ma zostać z odpowiedzią;
 *  4. nie da się w niej odhaczyć „już to widziałem", a bez tego `/health`
 *     nie umiałby przestać krzyczeć.
 *
 * Ta tabela NIE DUBLUJE `failed_jobs` — wskazuje na nią kolumną
 * `failed_job_uuid`. Adresu odbiorcy i treści listu tu nie ma i nie będzie:
 * są w payloadzie zadania, które `php artisan queue:failed` i tak pokazuje,
 * a druga kopia adresu e-mail w bazie to druga rzecz do skasowania przy
 * żądaniu RODO (AGENTS.md §7, minimalizacja).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST W SCHEMACIE I DLACZEGO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `failed_job_uuid` UNIKALNY — jeden przepadły list to jeden wiersz.
 * Unikalność jest bramką na podwójny zapis (zdarzenie `JobFailed` potrafi
 * dojść dwa razy, np. gdy worker padnie w trakcie sprzątania), a nie
 * ozdobą. NULLOWALNY, bo w trybie `sync` (konsola, testy) zadanie nie ma
 * wiersza w `failed_jobs` — a ślad ma zostać także wtedy. PostgreSQL
 * dopuszcza wiele NULL-i w kolumnie UNIQUE, więc jedno z drugim się nie bije.
 *
 * `powod` — kategoria z `App\Poczta\PowodOdmowy`, pilnowana CHECK-iem
 * W BAZIE, nie tylko w PHP (AGENTS.md §6). Walidator obchodzi się drugim
 * miejscem zapisu, CHECK nie.
 *
 * `user_id` — KTO CZEKAŁ NA TEN LIST, jeśli dało się to ustalić. To jest
 * najważniejsza kolumna tej tabeli dla właściciela: w grupie 50+ osoba,
 * która nie dostała potwierdzenia, nie napisze reklamacji — po prostu
 * odejdzie. `ON DELETE SET NULL`, bo ślad awarii ma przeżyć skasowanie
 * konta, a po skasowaniu nie ma już do kogo pisać.
 *
 * `komunikat` — powód po redakcji (`App\Poczta\BezpiecznyKomunikat`): bez
 * adresów e-mail, jedna linia, przycięty. Transport SMTP wkłada adres
 * odbiorcy wprost w komunikat błędu („550 5.1.1 <ktos@wp.pl>: Recipient
 * address rejected"), więc bez tej redakcji tabela byłaby dokładnie tą drugą
 * kopią adresów, której nie chcemy.
 *
 * `zauwazony_at` — „już to widziałem". Dopóki jest NULL-em, `/health` mówi
 * `degraded`. To jest jedyna kolumna, którą się w tym wierszu aktualizuje.
 *
 * `failed_at` zapisane WPROST, a nie jako `created_at`: ten wiersz opisuje
 * zdarzenie w czasie, a nie encję, która ma życiorys. Stąd też brak
 * `timestampsTz()` — nie ma czego aktualizować poza odhaczeniem.
 *
 * CZEGO W TEJ TABELI NIE MA, ŚWIADOMIE:
 *
 *  - adresu odbiorcy, tematu i treści listu (patrz wyżej);
 *  - liczby prób ponowienia przez człowieka. `queue:retry` tworzy nowe
 *    zadanie z nowym uuid, więc nieudane ponowienie zapisze się jako nowy
 *    wiersz — i tak jest uczciwiej, bo to była nowa próba, o innej godzinie;
 *  - kolumny „powiadomiono właściciela". Nie wysyłamy alarmu pocztą
 *    o awarii poczty (D-062 §3), więc nie ma czego notować.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — I DLACZEGO POTRAFI ODMÓWIĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * `php artisan migrate:rollback --step=1` kasuje tabelę, ale **tylko wtedy,
 * gdy nie ma w niej ani jednego NIEODHACZONEGO wiersza**. Nieodhaczony
 * wiersz to list, o którym właściciel jeszcze nie wie — a jedyne miejsce,
 * w którym ta wiedza istnieje, to ta tabela. Skasowanie jej razem z takim
 * wierszem byłoby powtórzeniem dokładnie tej usterki, którą ta migracja
 * naprawia: informacja o przepadłym liście znika i nikt się nie dowiaduje.
 *
 * Dlatego `down()` w takim przypadku RZUCA i mówi, co zrobić:
 *
 *     php artisan kuking:nieudane-listy            # przeczytaj, co przepadło
 *     php artisan kuking:nieudane-listy --odhacz   # potwierdź, że wiesz
 *     php artisan migrate:rollback --step=1        # teraz się uda
 *
 * Wiersze ODHACZONE giną razem z tabelą i to jest w porządku: właściciel je
 * przeczytał, a `failed_jobs` (i panel dostawcy) zostają.
 *
 * Wycofanie migracji BEZ wycofania kodu zostawia `/health`, komendę
 * `kuking:nieudane-listy` i listener kolejki przy nieistniejącej tabeli.
 * Sprawdzenie w `/health` zamienia się wtedy w `degraded` z kodem
 * `slad_listow_niesprawdzalny` (nie w 503 — poczta nie jest krytyczna),
 * a listener zapisuje porażkę do dziennika i milczy dalej, żeby nie zabrać
 * `failed_jobs` ostatniego zapisu. Kolejność wycofywania jest więc taka:
 * NAJPIERW KOD, POTEM MIGRACJA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Wskaźnik na `failed_jobs.uuid` — bez klucza obcego CELOWO:
            // `queue:retry` i `queue:flush` kasują tamten wiersz, a ten ma
            // zostać. Klucz obcy zamieniłby sprzątanie kolejki w kasowanie
            // historii awarii.
            $table->uuid('failed_job_uuid')->nullable()->unique();

            // Kategoria odmowy (`App\Poczta\PowodOdmowy`). 20 znaków
            // z zapasem — najdłuższa dzisiejsza wartość ma 12.
            $table->string('powod', 20);

            // Kod HTTP odpowiedzi dostawcy. NULL, gdy w ogóle nie odpowiedział
            // (zerwane połączenie) albo gdy transport nie chodzi po HTTP.
            $table->smallInteger('status_http')->nullable();

            // Nazwa zadania z payloadu kolejki — czyli KLASA POWIADOMIENIA,
            // np. `App\Notifications\PotwierdzenieAdresu`. To ona mówi
            // właścicielowi, CO przepadło: potwierdzenie rejestracji boli
            // inaczej niż tygodniowe podsumowanie.
            $table->string('rodzaj', 255);

            // Kolejka, z której zadanie spadło (`high`, `default`, `low`).
            $table->string('kolejka', 100)->nullable();

            // Ile prób wykonał worker, zanim uznał list za przepadły.
            $table->smallInteger('prob')->default(1);

            // Kto czekał na list. `nullOnDelete`, bo ślad przeżywa konto.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Powód po redakcji adresów (`BezpiecznyKomunikat`).
            $table->text('komunikat')->nullable();

            $table->timestampTz('failed_at');
            $table->timestampTz('zauwazony_at')->nullable();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE mail_failures ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // CHECK-i idą DO BAZY. Lista wartości pochodzi z jednego miejsca
        // (`PowodOdmowy`), a `NieudanyListZostawiaSladTest` porównuje ją
        // z tym, co baza faktycznie przyjmuje — żeby dopisanie piątej
        // kategorii w PHP nie skończyło się cichym `QueryException`
        // w listenerze, czyli utratą śladu przy pierwszej awarii nowego typu.
        $dozwolone = implode(', ', array_map(
            static fn (string $powod): string => "'".$powod."'",
            PowodOdmowy::wartosci(),
        ));

        DB::statement(<<<SQL
            ALTER TABLE mail_failures
            ADD CONSTRAINT mail_failures_powod_check
            CHECK (powod IN ({$dozwolone}))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mail_failures
            ADD CONSTRAINT mail_failures_status_http_check
            CHECK (status_http IS NULL OR (status_http BETWEEN 100 AND 599))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mail_failures
            ADD CONSTRAINT mail_failures_prob_check
            CHECK (prob >= 1)
        SQL);

        // Odhaczenie nie może być WCZEŚNIEJSZE niż awaria. Bez tego
        // pomyłka w kodzie („odhacz wszystko datą sprzed tygodnia")
        // wyciszałaby alarm o liście, który przepadł dziś.
        DB::statement(<<<'SQL'
            ALTER TABLE mail_failures
            ADD CONSTRAINT mail_failures_zauwazony_po_awarii_check
            CHECK (zauwazony_at IS NULL OR zauwazony_at >= failed_at)
        SQL);

        // Indeks CZĘŚCIOWY pod jedyne zapytanie, które chodzi w żądaniu HTTP:
        // `/health` pyta, czy jest cokolwiek nieodhaczonego. Pełny indeks po
        // `failed_at` byłby tu większy i mniej użyteczny — nieodhaczonych
        // wierszy ma być zero przez większość czasu.
        DB::statement(<<<'SQL'
            CREATE INDEX mail_failures_nieodhaczone_idx
            ON mail_failures (failed_at DESC)
            WHERE zauwazony_at IS NULL
        SQL);

        // Pod ekran „Potwierdź adres e-mail": czy TEMU człowiekowi przepadł
        // TAKI list. Częściowy, bo wiersz bez `user_id` nie odpowiada na to
        // pytanie w ogóle.
        DB::statement(<<<'SQL'
            CREATE INDEX mail_failures_adresat_idx
            ON mail_failures (user_id, rodzaj, failed_at DESC)
            WHERE user_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // ROLLBACK ODMAWIA, GDY ZNISZCZYŁBY WIEDZĘ, KTÓREJ NIE MA GDZIE INDZIEJ.
        // Uzasadnienie w komentarzu nad klasą.
        if (Schema::hasTable('mail_failures')) {
            $nieodhaczone = DB::table('mail_failures')->whereNull('zauwazony_at')->count();

            if ($nieodhaczone > 0) {
                throw new RuntimeException(
                    'Odmawiam wycofania migracji: w `mail_failures` leży '.$nieodhaczone.' '
                    .'nieodhaczonych wierszy, czyli tyle listów przepadło, a nikt tego nie potwierdził. '
                    .'Skasowanie tej tabeli usunęłoby JEDYNY ślad tych awarii. '
                    .'Przeczytaj je (`php artisan kuking:nieudane-listy`), potwierdź '
                    .'(`php artisan kuking:nieudane-listy --odhacz`) i powtórz wycofanie.',
                );
            }
        }

        Schema::dropIfExists('mail_failures');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
