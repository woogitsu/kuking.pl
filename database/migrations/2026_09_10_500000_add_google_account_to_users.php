<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Powiązanie konta Kuking z kontem Google (issue #258, D-069).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TRZYMAMY I DLACZEGO AKURAT TYLE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Dwie kolumny i ani jednej więcej:
 *
 *  - `google_sub` — identyfikator konta Google (`sub` z tokenu tożsamości).
 *    To JEDYNA wartość, którą Google obiecuje jako trwałą: adres e-mail
 *    da się u Google zmienić, imię też, a `sub` zostaje ten sam przez całe
 *    życie konta. Bez niego rozpoznawalibyśmy człowieka przy kolejnym
 *    wejściu po adresie e-mail — czyli po wartości, która może w międzyczasie
 *    trafić do KOGOŚ INNEGO (Google Workspace: adres zwolnionego pracownika
 *    da się nadać nowej osobie). Adres służy JEDEN raz, przy pierwszym
 *    połączeniu, i już nigdy do rozpoznania konta.
 *  - `google_connected_at` — kiedy powiązanie powstało. Potrzebne do dwóch
 *    rzeczy, których `audit_log` nie załatwia: żeby ekran ustawień mógł
 *    kiedyś powiedzieć „połączone od…", i żeby przy sporze o dostęp do konta
 *    dało się odpowiedzieć na pytanie „od kiedy". `audit_log` jest sprzątany
 *    z czasem, a to jest cecha konta.
 *
 * CZEGO TU NIE MA, ŚWIADOMIE (issue #258 pkt 5):
 *
 *  - **tokenu dostępu i tokenu odświeżania.** Nie wołamy żadnego API Google
 *    po zalogowaniu, więc token po wymianie kodu jest nam niepotrzebny
 *    w następnej sekundzie. Żądanie autoryzacji idzie z `access_type=online`,
 *    czyli Google tokenu odświeżania NAM NIE WYSTAWIA — nie chodzi więc
 *    o to, że go nie zapisujemy, chodzi o to, że go nie dostajemy.
 *    Token odświeżania w naszej bazie byłby trwałym pełnomocnictwem do
 *    cudzego konta Google, leżącym w serwisie, który go do niczego nie używa.
 *  - **tokenu tożsamości** (`id_token`). Żyje przez jedno wywołanie akcji,
 *    w którym odczytujemy z niego `sub`, adres i `email_verified`.
 *  - **zdjęcia z Google** (`picture`). D-061: każde zdjęcie profilowe
 *    przechodzi u nas przez moderację modelem i przez własny pipeline
 *    przekodowania. Zdjęcie zaciągnięte z zewnątrz weszłoby POZA tę drogę.
 *  - **nazwy konta Google ani nazwy domeny.** Imię z Google służy raz, jako
 *    PODPOWIEDŹ na ekranie domknięcia konta, i nie jest zapisywane jako
 *    „imię z Google" — zapisujemy to, co człowiek zatwierdził albo zmienił.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO KOLUMNY NA `users`, A NIE OSOBNA TABELA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `login_link_tokens` i `pending_email_changes` (D-048) dostały własne
 * tabele, bo to są ŻĄDANIA Z ŻYCIORYSEM: powstają, wygasają, zostają zużyte.
 * Powiązanie z Google jest czymś innym — to TRWAŁA CECHA KONTA, jak adres
 * e-mail albo `age_confirmed_at`. Nie wygasa, nie zużywa się i jest czytane
 * dokładnie wtedy, gdy i tak czytamy wiersz `users`: przy wejściu na konto.
 *
 * Osobna tabela `tozsamosci_zewnetrzne` (`provider`, `subject`) byłaby
 * właściwym kształtem przy DWÓCH dostawcach. Dziś jest jeden i AGENTS.md §3
 * mówi wprost, żeby nie budować na zapas. Próg powrotu jest w D-069 i jest
 * tani: pierwszy drugi dostawca (issue #258 wspomina Facebooka jako
 * „ewentualnie"). Migracja przejściowa to wtedy jeden `INSERT ... SELECT`
 * plus skasowanie dwóch kolumn.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO PILNUJE BAZA, A NIE PHP
 * ────────────────────────────────────────────────────────────────────────
 *
 * 1. **UNIKALNOŚĆ `google_sub`** — jedno konto Google wchodzi na dokładnie
 *    JEDNO konto Kuking. Bez tego dwa nasze konta mogłyby wskazywać ten sam
 *    `sub`, a wtedy „wejdź kontem Google" musiałoby wybierać, na które
 *    z nich wpuścić — i wybrałoby to, które baza akurat poda pierwsze.
 *    Indeks jest CZĘŚCIOWY (`WHERE google_sub IS NOT NULL`), bo kont bez
 *    Google jest i będzie większość, a `UNIQUE` po kolumnie z NULL-ami
 *    trzymałby w indeksie każdy z nich bez powodu.
 * 2. **OBIE KOLUMNY ALBO ŻADNA** (`num_nonnulls(...) IN (0, 2)`). Wiersz
 *    z `google_sub` bez daty połączenia albo z datą bez `sub` jest stanem,
 *    którego kod nie umie wytłumaczyć, a przy tej funkcji „nie umiem
 *    wytłumaczyć" dotyczy pytania „kto ma wejście na to konto".
 * 3. **KSZTAŁT `sub`** — niepusty, bez znaków białych, do 255 znaków
 *    (OpenID Connect Core §2 dopuszcza do 255 znaków ASCII). Nie zawężamy
 *    do samych cyfr, choć dziś Google nadaje 21-cyfrowe wartości: zawężenie
 *    do dzisiejszego kształtu cudzego identyfikatora zamknęłoby logowanie
 *    w dniu, w którym Google go zmieni, a nie chroniłoby przed niczym.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — ODMAWIA, GDY KOMUŚ ZABRAŁBY WEJŚCIE NA KONTO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` kasuje obie kolumny, czyli KASUJE POWIĄZANIA. Dla konta
 * założonego drogą Google to nie jest strata „linku w drodze" jak przy
 * D-056 — to może być jedyna droga wejścia, jaką ten człowiek zna: hasła
 * nigdy nie ustawiał (kolumna `password` ma losowy skrót), a adres e-mail
 * jest potwierdzony, więc zostaje mu odzyskiwanie hasła i link e-mail.
 *
 * Dlatego `down()` **odmawia**, gdy w bazie jest choć jedno konto powiązane
 * z Google, i mówi, co zrobić: najpierw wyłącz funkcję zmienną środowiskową
 * (`KUKING_WEJSCIE_GOOGLE=false` — to jest wycofanie bez migracji), a jeśli
 * naprawdę chcesz skasować powiązania, potwierdź to jawnie:
 *
 *     KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE=true php artisan migrate:rollback --step=1
 *
 * Ta sama zasada co przy `CofniecieMigracjiNieKasujeZeszytowTest`: cofnięcie
 * migracji wolno wykonać, ale nie wolno mu po cichu zabrać ludziom danych,
 * których potrzebują, żeby wejść na własne konto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // 255 znaków, bo tyle dopuszcza OpenID Connect dla `sub`.
            // Dziś Google nadaje 21 cyfr — kolumna nie ma prawa być ciaśniejsza
            // od cudzej specyfikacji, bo to jest wartość, której nie ustalamy my.
            $table->string('google_sub', 255)->nullable();
            $table->timestampTz('google_connected_at')->nullable();
        });

        if (! $this->isPostgres()) {
            return;
        }

        // JEDNO KONTO GOOGLE = JEDNO KONTO KUKING. Indeks częściowy, bo
        // większość kont nie ma i nie będzie miała powiązania.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX users_google_sub_unique
            ON users (google_sub)
            WHERE google_sub IS NOT NULL
        SQL);

        // OBIE KOLUMNY ALBO ŻADNA — patrz komentarz nagłówkowy, punkt 2.
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_google_pair_check
            CHECK (num_nonnulls(google_sub, google_connected_at) IN (0, 2))
        SQL);

        // KSZTAŁT `sub`: niepusty, bez znaków białych.
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_google_sub_format_check
            CHECK (google_sub IS NULL OR google_sub ~ '^\S{1,255}$')
        SQL);
    }

    public function down(): void
    {
        $powiazane = $this->ilePowiazanych();

        if ($powiazane > 0 && ! $this->wolnoKasowacPowiazania()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby powiązania z kontem Google dla '
                .$powiazane.' kont — dla części z nich to jedyna droga wejścia, jaką znają '
                ."(hasła nigdy nie ustawiały).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Wyłącz funkcję bez migracji: KUKING_WEJSCIE_GOOGLE=false i restart serwisu. '
                ."Przycisk znika, konta działają dalej, powiązania zostają nietknięte.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ SKASOWAĆ POWIĄZANIA\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE=true '
                .'php artisan migrate:rollback --step=1 — i uprzedź te osoby, '
                .'że wejdą hasłem („Nie pamiętam hasła”) albo linkiem e-mail.',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_google_sub_format_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_google_pair_check');
            DB::statement('DROP INDEX IF EXISTS users_google_sub_unique');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['google_sub', 'google_connected_at']);
        });
    }

    /**
     * Ile kont ma dziś powiązanie z Google.
     *
     * Pytamy `DB::table()`, a nie modelu: `down()` musi działać także wtedy,
     * gdy kod aplikacji jest już wycofany albo zmieniony.
     */
    private function ilePowiazanych(): int
    {
        if (! Schema::hasColumn('users', 'google_sub')) {
            return 0;
        }

        return (int) DB::table('users')->whereNotNull('google_sub')->count();
    }

    private function wolnoKasowacPowiazania(): bool
    {
        return filter_var(
            env('KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
