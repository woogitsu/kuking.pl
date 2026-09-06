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
 * ROLLBACK
 * Bezpieczny dla schematu, ale NIE dla kont z włączonym 2FA: `down()` kasuje
 * wszystkie cztery kolumny, czyli każde konto traci zapisany sekret i kody
 * zapasowe. Każdy moderator z włączonym 2FA wraca do logowania samym hasłem
 * — to jest świadomy powrót do stanu SPRZED tej zmiany, nie utrata dostępu:
 * nikt nie zostaje zablokowany, bo wymóg drugiego składnika znika razem
 * z kolumnami, które go przechowywały. Wycofanie tej migracji ma sens
 * wyłącznie jako awaryjne zdjęcie całej funkcji (np. błąd w bibliotece TOTP),
 * nie jako codzienna operacja.
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
