<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zaproszenie do założenia konta — dla adresu, na którym konta NIE MA (D-067).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA ISTNIEJE — PRAWDZIWE ZDARZENIE, NIE HIPOTEZA
 * ────────────────────────────────────────────────────────────────────────
 *
 * 63-letnia osoba chciała założyć konto, odbiła się o walidację nazwy
 * użytkownika, przeszła na ekran „Wyślij mi link do zalogowania", wpisała swój
 *
 * adres i zobaczyła zielone „Wysłaliśmy wiadomość na e***@gmail.com".
 * Nie wyszło nic — bo pod tym adresem nie było konta, a ten ekran ŚWIADOMIE
 * odpowiada identycznie dla adresu z kontem i bez konta (D-056), żeby nie
 * zdradzać, kto ma konto w Kuking. Czekała na wiadomość, która nie miała
 * przyjść.
 *
 * Od teraz taki adres dostaje wiadomość z linkiem prowadzącym na DOKOŃCZENIE
 * ZAKŁADANIA KONTA. Ten wiersz jest tym zaproszeniem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNA TABELA, A NIE `login_link_tokens`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo tamta tabela wisi na `user_id` z prawdziwym kluczem obcym, a tutaj konta
 * nie ma i mieć nie musi. Wpuszczenie tu wiersza z `user_id IS NULL`
 * zabrałoby tamtej tabeli jedyną rzecz, która ją pilnuje w bazie: warunek
 * „jeden ważny link na konto" (`user_id` UNIQUE) przestałby cokolwiek znaczyć
 * dla wierszy bez konta, a każde zapytanie musiałoby pamiętać, o który rodzaj
 * wiersza pyta. To ta sama klasa błędu co „AND used_at IS NULL", którą D-056
 * odrzuciło wprost.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST W SCHEMACIE I DLACZEGO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `email` — adres w postaci JAWNEJ i jest to konieczne, nie wygoda: formularz
 * rejestracji dostaje ten adres wpisany i NIEZMIENNY, a bierze go z tego
 * wiersza, nie z pola w przeglądarce. Skrót by tego nie umiał. Kolumna jest
 * UNIKALNA — jedno ważne zaproszenie na adres, kolejna prośba zastępuje
 * poprzednią (ta sama decyzja co `login_link_tokens.user_id`, ten sam powód:
 * bez tego ktoś zbierałby sobie zapas ważnych zaproszeń „na później").
 *
 * TO JEST DANA OSOBOWA OSOBY, KTÓRA NIE MA U NAS KONTA I NIGDY NIE MUSI MIEĆ
 * — dlatego termin jest krótki, a wiersz po wygaśnięciu kasuje
 * `kuking:sprzataj-zaproszenia` (ten sam wzorzec co `pending_email_changes`,
 * AGENTS.md §7 — minimalizacja danych).
 *
 * `token_hash` — **HMAC-SHA256 tokenu, nigdy token** (`App\Support\Skrot`),
 * kolumna UNIKALNA, bo po niej szukamy wiersza przy kliknięciu w link.
 * Skrót szybki, a nie bcrypt — cały wywód jest przy `login_link_tokens`
 * i obowiązuje tu bez zmian: token ma 64 losowe znaki, więc nie ma czego
 * spowalniać, a bcrypt uniemożliwiłby wyszukanie wiersza po skrócie.
 *
 * `expires_at` ZAPISANE W WIERSZU, nie liczone przy odczycie — ten sam powód
 * co w `pending_email_changes` i `login_link_tokens`: termin ma być faktem
 * policzonym raz, a nie arytmetyką na datach przy każdym sprawdzeniu.
 *
 * CZEGO W TEJ TABELI NIE MA, ŚWIADOMIE:
 *
 *  - `used_at`. Zaproszenie zużyte znika (`DELETE` w tej samej transakcji,
 *    w której powstaje konto). Ten sam wywód co przy `login_link_tokens`:
 *    wiersz po użyciu nie odpowiadałby na żadne pytanie, którego nie
 *    odpowiada `audit_log`, a byłby kolejnym miejscem z warunkiem
 *    „AND used_at IS NULL" do zapamiętania.
 *  - ADRESU IP PROSZĄCEGO — i to jest odstępstwo od pierwotnego pomysłu na tę
 *    tabelę, świadome. Adres IP tej prośby jest naprawdę potrzebny (to jedyne
 *    miejsce w serwisie, w którym nieznajomy każe nam wysłać wiadomość na
 *    dowolny adres), ale ma już swoje miejsce: `audit_log.ip_hash` przy wpisie
 *    `account.registration_invite_sent`, w skrócie i z retencją 12 miesięcy.
 *    Druga kopia tutaj byłaby kolejnym zbiorem adresów IP w bazie
 *    (AGENTS.md §7) — dokładnie tym, czego D-056 odmówiło przy
 *    `login_link_tokens`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — I DLACZEGO ODMAWIA, GDY MA CO ZNISZCZYĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * `php artisan migrate:rollback --step=1`. `down()` kasuje tabelę, ale
 * NAJPIERW SPRAWDZA, czy nie ma w niej ważnych zaproszeń — i jeśli są,
 * ODMAWIA. To nie jest ostrożność na zapas: wiersz z ważnym terminem to
 * człowiek, który ma w skrzynce wiadomość i jeszcze jej nie kliknął. Jego
 * zaproszenie ginie bez śladu i bez powiadomienia, a on zobaczy „ten link już
 * nie działa" — czyli dokładnie to, co ta funkcja miała naprawić.
 *
 * Odmowa daje operatorowi wybór, którego `dropIfExists()` mu nie daje:
 * poczekać do wygaśnięcia najstarszego zaproszenia (najwyżej
 * `login_link.zaproszenia.waznosc_godzin`, domyślnie 24 h), albo
 * `php artisan kuking:sprzataj-zaproszenia --wszystkie` i wycofać świadomie.
 * Wiersze WYGASŁE odmowy nie wywołują — one nikomu już nie służą.
 *
 * Wycofanie migracji BEZ wycofania kodu zostawia trasy `/zaproszenie/...`
 * i ścieżkę wysyłki odwołujące się do nieistniejącej tabeli — czyli 500 na
 * publicznym formularzu. Kolejność wycofywania: **NAJPIERW KOD, POTEM
 * MIGRACJA**. A jeśli chodzi tylko o wyłączenie funkcji, migracja nie jest do
 * tego potrzebna wcale: `KUKING_ZAPROSZENIA_DO_REJESTRACJI=false` przywraca
 * zachowanie z D-056 (adres bez konta nie dostaje nic) bez wdrożenia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // JEDNO ważne zaproszenie na adres — kolejna prośba zastępuje
            // poprzednią, więc link z wcześniejszej wiadomości przestaje
            // działać w tej samej chwili.
            $table->string('email', 255)->unique();

            // 64 znaki: HMAC-SHA256 zapisany szesnastkowo.
            $table->string('token_hash', 64)->unique();

            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');

            // Sprzątanie chodzi po tej kolumnie raz na dobę.
            $table->index('expires_at', 'registration_invites_expires_at_index');
        });

        if (! $this->isPostgres()) {
            return;
        }

        // CHECK-i w bazie, nie tylko w PHP (AGENTS.md §6). Walidator obchodzi
        // się drugim endpointem, CHECK nie.

        // Adres zapisujemy ZNORMALIZOWANY (małe litery), bo tak samo szuka go
        // `users.email` — inaczej „Basia@wp.pl" i „basia@wp.pl" byłyby dwoma
        // różnymi zaproszeniami na jedną skrzynkę i UNIQUE wyżej nic by nie
        // pilnował.
        DB::statement(<<<'SQL'
            ALTER TABLE registration_invites
            ADD CONSTRAINT registration_invites_email_lower_check
            CHECK (email = lower(email) AND email <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE registration_invites
            ADD CONSTRAINT registration_invites_expires_after_created_check
            CHECK (expires_at > created_at)
        SQL);

        // SKRÓT MA BYĆ SKRÓTEM: 64 znaki szesnastkowe małymi literami.
        //
        // To nie jest ozdoba schematu, tylko bramka na jedyny błąd, którego ta
        // tabela nie ma prawa przeżyć: zapisanie tokenu JAWNIE. Token powstaje
        // przez `Str::random(64)` — z wielkimi literami — więc wpisany tu
        // wprost łamie ten warunek i baza go odrzuci. Dlatego token NIE JEST
        // szesnastkowy i nie wolno go na taki zmienić: zabrałoby to CHECK-owi
        // całą wartość.
        DB::statement(<<<'SQL'
            ALTER TABLE registration_invites
            ADD CONSTRAINT registration_invites_token_hash_format_check
            CHECK (token_hash ~ '^[0-9a-f]{64}$')
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('registration_invites')) {
            return;
        }

        $wazne = DB::table('registration_invites')->where('expires_at', '>', now())->count();

        if ($wazne > 0) {
            throw new RuntimeException(
                "Wycofanie odmówione: w `registration_invites` jest {$wazne} WAŻNYCH zaproszeń do "
                .'założenia konta. Każde z nich to człowiek, który ma w skrzynce wiadomość i jeszcze '
                ."jej nie kliknął — skasowanie tabeli zabiera mu drogę do konta bez słowa.\n"
                ."Masz dwa wyjścia:\n"
                .'  1. poczekać, aż zaproszenia wygasną (najwyżej `login_link.zaproszenia.waznosc_godzin`, '
                ."domyślnie 24 h), a potem wycofać migrację — wygasłe wiersze odmowy nie wywołują;\n"
                .'  2. `php artisan kuking:sprzataj-zaproszenia --wszystkie` i wycofać świadomie, '
                .'wiedząc, że te osoby zobaczą „ten link już nie działa".',
            );
        }

        Schema::drop('registration_invites');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
