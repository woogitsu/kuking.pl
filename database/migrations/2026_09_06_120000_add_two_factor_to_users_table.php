<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weryfikacja dwuetapowa (TOTP) na koncie (issue #12, część 2FA).
 *
 * PROBLEM
 * Konto moderatora i administratora chroni dziś samo hasło. Moderator widzi
 * zgłoszenia, cudze ukryte treści i odwołania — przejęcie tego konta nie
 * jest incydentem na jednym koncie, tylko wyciekiem wszystkiego, co przez
 * moderację przechodzi. `docs/SECURITY_PRIVACY_LEGAL.md` obiecuje „MFA
 * obowiązkowe dla adminów” — ta migracja daje temu miejsce w bazie.
 *
 * DLACZEGO TOTP, NIE E-MAIL
 * Serwis nie ma dziś działającego SMTP (zadanie po stronie właściciela) —
 * drugi składnik oparty o e-mail zależałby od kanału, który nie działa.
 * TOTP (Google Authenticator, Aegis, 1Password…) liczy kod lokalnie,
 * offline, z samego sekretu i aktualnego czasu.
 *
 * KOLUMNY
 * - `two_factor_secret` — sekret TOTP, zaszyfrowany (`encrypted` cast
 *   w App\Models\User). Wyciek kopii bazy nie może oddawać drugiego
 *   składnika, więc w kolumnie nie ma nigdy jawnego tekstu.
 * - `two_factor_confirmed_at` — moment, w którym człowiek udowodnił, że
 *   sekret naprawdę trafił do jego aplikacji (wpisał poprawny kod przy
 *   włączaniu). Sekret bywa zapisany PRZED tym momentem (ekran włączenia go
 *   pokazuje), ale dopóki `confirmed_at` jest NULL, 2FA nie jest aktywne —
 *   nikt nie może zostać zablokowany kodem, którego jeszcze nie potwierdził.
 * - `two_factor_backup_codes` — kody zapasowe, TYLKO jako tablica skrótów
 *   (`encrypted:array`, każdy element to hash z `Hash::make()`, nie kod
 *   wprost). Zużyty kod jest usuwany z tablicy przy weryfikacji — to
 *   jednocześnie realizuje „kod zapasowy działa RAZ” i nie wymaga osobnej
 *   kolumny na ich zliczanie.
 * - `two_factor_last_used_at` — NIE jest to `timestamptz` mimo nazwy: to
 *   surowy licznik czasu Uniksa zwracany przez algorytm TOTP
 *   (`Google2FA::verifyKeyNewer()`), używany WYŁĄCZNIE do porównania
 *   „czy ten kod jest nowszy niż ostatnio zaakceptowany”. Bez tego ten sam
 *   sześciocyfrowy kod, ważny przez całe okno tolerancji (±30 s), dałoby się
 *   wpisać drugi raz i wejść nim ponownie (atak powtórzenia). Kolumna jest
 *   `bigint`, nie `timestamptz`, celowo — aplikacja nigdy nie odpytuje jej
 *   funkcjami dat, tylko przekazuje z powrotem do tej samej biblioteki.
 *
 * CHECK W BAZIE (AGENTS.md §6: ograniczenie ma być w bazie, nie tylko w PHP)
 * Konto nie może mieć potwierdzonego 2FA bez zapisanego sekretu — inaczej
 * `confirmed_at` byłby stanem bez znaczenia i blokowałby dostęp do panelu
 * bez możliwości podania jakiegokolwiek kodu.
 *
 * ROLLBACK — ODMAWIA, gdy jakiekolwiek konto ma 2FA potwierdzone (D-238, D-088)
 * `down()` kasuje wszystkie cztery kolumny, więc każde konto traci sekret TOTP
 * i kody zapasowe — bezpowrotnie, bo sekret jest zaszyfrowany i nie da się go
 * odtworzyć z niczego innego.
 *
 * Stało tu wcześniej, że „nikt nie zostaje zablokowany, bo wymóg drugiego
 * składnika znika razem z kolumnami". To prawda i dlatego właśnie jest groźne:
 * cofnięcie nie wybija nikogo z serwisu — po cichu ZDEJMUJE ochronę. Cykl
 * `rollback` → `migrate`, który CI wykonuje jako `migrate:refresh`, zostawia
 * kolumny puste, a razem z nimi znika CHECK pilnujący niezmiennika. Konto
 * moderatora, o którym właściciel wie, że jest chronione dwoma składnikami,
 * wraca do samego hasła i nikt się o tym nie dowiaduje. To jest dokładnie
 * „przywracanie stanu groźnego" z zasady D-088.
 *
 * Dlatego liczy się `two_factor_confirmed_at IS NOT NULL`, a nie sam sekret:
 * sekret zapisany bez potwierdzenia to konto W TRAKCIE włączania 2FA (ekran
 * pokazuje sekret przed wpisaniem pierwszego kodu). Taki stan nie jest
 * ochroną, którą można stracić — człowiek po prostu zaczyna włączanie od nowa.
 *
 * Na świeżym środowisku, gdzie nikt 2FA nie potwierdził, cofnięcie działa bez
 * pytania — więc `migrate:refresh` w CI i u dewelopera chodzi jak dotąd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_backup_codes')->nullable()->after('two_factor_secret');
            $table->timestampTz('two_factor_confirmed_at')->nullable()->after('two_factor_backup_codes');

            // Uwaga w komentarzu klasy: to jest licznik czasu Uniksa z biblioteki
            // TOTP, nie „moment" w rozumieniu reszty schematu — stąd bigint,
            // nie timestamptz.
            $table->unsignedBigInteger('two_factor_last_used_at')->nullable()->after('two_factor_confirmed_at');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_two_factor_confirmed_requires_secret_check
            CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        // STRAŻNIK PRZED CICHYM ZDJĘCIEM DRUGIEGO SKŁADNIKA (D-238, D-088).
        // MUSI stać przed KAŻDĄ operacją niżej — także przed zdjęciem CHECK-a,
        // nie tylko przed `dropColumn`. Sprawdzenie po fakcie chroniłoby sam
        // komunikat, nie dane (ten sam błąd kolejności, którego pilnuje
        // `CofniecieMigracjiNieKasujeZeszytowTest`).
        //
        // `DB::table()`, nie surowe SQL: to sprawdzenie ma działać na każdym
        // sterowniku, bo `dropColumn` niżej wykonuje się bezwarunkowo, a nie
        // tylko pod `isPostgres()`.
        $zPotwierdzonym = DB::table('users')->whereNotNull('two_factor_confirmed_at')->count();

        if ($zPotwierdzonym > 0 && getenv('KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK') !== '1') {
            // `getenv()`, NIE `env()`. Na produkcji konfiguracja jest zbuforowana
            // (`config:cache`), a wtedy `env()` zwraca `null` — furtka nie
            // zadziałałaby dokładnie tam, gdzie jest potrzebna.
            //
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „1 kont"
            // to nie polszczyzna, a jedno konto jest stanem prawdopodobniejszym
            // niż pięć. Mianownik przed dwukropkiem nie odmienia się wcale,
            // więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Liczba kont z potwierdzoną weryfikacją dwuetapową: '.$zPotwierdzonym.'. '.
                'Cofnięcie tej migracji skasuje ich sekrety TOTP i kody zapasowe — bezpowrotnie, '.
                'bo sekret jest zaszyfrowany i nie ma go skąd odtworzyć. Te konta nie zostaną '.
                'zablokowane: wrócą do logowania SAMYM HASŁEM, po cichu i bez ostrzeżenia dla '.
                'ich właścicieli. Przy koncie moderatora albo administratora to jest zdjęcie '.
                "ochrony, nie porządki (D-238, zasada D-088).\n\n".
                "CO ZROBIĆ:\n".
                '  - jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '.
                'cofaj TEJ migracji — kod sprzed niej nie zna tych kolumn i działa z nimi bez '.
                'zmian (rollback obrazu i rollback bazy to dwie różne decyzje);'."\n".
                '  - jeśli naprawdę trzeba cofnąć SCHEMAT, najpierw powiadom te konta, że drugi '.
                "składnik przestanie działać, i zapisz listę:\n".
                "      SELECT id, email FROM users WHERE two_factor_confirmed_at IS NOT NULL;\n".
                '    po powrocie na tę wersję schematu każde z nich musi włączyć 2FA OD NOWA — '.
                "starych sekretów nie da się przywrócić;\n".
                '  - dopiero wtedy uruchom ponownie z KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK=1.',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_two_factor_confirmed_requires_secret_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_backup_codes',
                'two_factor_confirmed_at',
                'two_factor_last_used_at',
            ]);
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
