<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push i ustawienia kanałów poza serwisem (issue #35, D-303).
 *
 * TRZY RZECZY W JEDNEJ MIGRACJI, bo żadna nie ma sensu bez pozostałych:
 *
 *  - `push_subscriptions` — przeglądarki, na które człowiek sam (kliknięciem
 *    w ustawieniach) pozwolił wysyłać powiadomienia. Wiersz znika, gdy usługa
 *    push odpowie 404/410, przy wyłączeniu i przy wymazaniu konta.
 *  - `ustawienia_powiadomien_zewnetrznych` — cisza nocna i dzienny limit
 *    WYBRANE przez człowieka. Brak wiersza = wartości domyślne z konfiguracji
 *    (`kuking.notifications.zewnetrzne`), więc zmiana domyślnych nie wymaga
 *    przepisywania danych.
 *  - `notifications.push_wyslano_at` — kiedy powiadomienie NAPRAWDĘ poszło
 *    pushem, czyli transport PRZYJĄŁ wiadomość na WSZYSTKIE urządzenia tej
 *    grupy (issue #1960). Jedno pole daje dwie rzeczy: „co jeszcze czeka"
 *    (NULL) i „ile pushy wyszło dziś" (liczba różnych znaczników w dobie
 *    odbiorcy — kilka powiadomień zgrupowanych w jednym pushu dostaje ten
 *    sam znacznik).
 *  - `notifications.push_proba_at` — kiedy `WyslijPowiadomieniePush` ZAJĘŁO
 *    tę grupę powiadomień do wysyłki, niezależnie od tego, czy się udało.
 *    Bariera przed dublem: dopóki jest ustawione, żadne INNE zadanie tego
 *    samego odbiorcy nie wybierze tej samej grupy jeszcze raz — ponowienie
 *    po błędzie transportu dostaje listę powiadomień i subskrypcji wprost
 *    od poprzedniej próby, nie przez ponowne zapytanie „co czeka". Bez tego
 *    rozdzielenia błąd choćby jednego urządzenia (albo trwała porażka po
 *    wyczerpaniu prób) zostawiał `push_wyslano_at` ustawiony na kłamstwo
 *    (issue #1960) — patrz `App\Jobs\WyslijPowiadomieniePush`.
 *
 * `ALTER TABLE notifications ADD COLUMN … NULL` bez wartości domyślnej to
 * zmiana samego katalogu — bez przepisywania tabeli i bez skanu; blokadę
 * i tak ogranicza `lock_timeout` z `LimitBlokadMigracji` (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA, GDY KTOŚ WYBRAŁ WŁASNĄ CISZĘ NOCNĄ ALBO LIMIT (D-088).
 * Po `down()` przychodzi zwykle kolejny `migrate` — tabela wraca pusta, czyli
 * z domyślnym 21–8, a nie z godzinami, które człowiek sam ustawił. To jest
 * dokładnie „zmiana znaczenia decyzji człowieka po cichu". Subskrypcje same
 * w sobie nie blokują wycofania: ich utrata gasi push (stan bezpieczny —
 * człowiek dostaje MNIEJ, nie więcej), a w serwisie nic nie ginie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Adres usługi push przydzielony przeglądarce. Unikalny globalnie:
            // jedna przeglądarka = jedna subskrypcja, niezależnie od konta.
            $table->text('endpoint');
            // Klucze szyfrowania treści od przeglądarki (base64url).
            $table->string('klucz_p256dh', 200);
            $table->string('klucz_auth', 100);
            $table->string('kodowanie', 16)->default('aes128gcm');
            $table->timestampsTz();

            $table->index('user_id');
        });

        Schema::create('ustawienia_powiadomien_zewnetrznych', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->smallInteger('cisza_od');
            $table->smallInteger('cisza_do');
            $table->smallInteger('dzienny_limit');
            $table->timestampsTz();
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('push_wyslano_at')->nullable();
            $table->timestampTz('push_proba_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE push_subscriptions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('CREATE UNIQUE INDEX push_subscriptions_endpoint_unique ON push_subscriptions (md5(endpoint))');
            DB::statement("ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_endpoint_https_check CHECK (endpoint LIKE 'https://%' AND length(endpoint) <= 2048)");
            DB::statement("ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_kodowanie_check CHECK (kodowanie IN ('aes128gcm', 'aesgcm'))");
            DB::statement('ALTER TABLE ustawienia_powiadomien_zewnetrznych ADD CONSTRAINT ustawienia_powiadomien_zewnetrznych_cisza_check CHECK (cisza_od BETWEEN 0 AND 23 AND cisza_do BETWEEN 0 AND 23)');
            DB::statement('ALTER TABLE ustawienia_powiadomien_zewnetrznych ADD CONSTRAINT ustawienia_powiadomien_zewnetrznych_limit_check CHECK (dzienny_limit BETWEEN 1 AND 10)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ustawienia_powiadomien_zewnetrznych')
            && DB::table('ustawienia_powiadomien_zewnetrznych')->exists()) {
            throw new RuntimeException(
                'Wycofanie odmówione (D-088): w `ustawienia_powiadomien_zewnetrznych` są godziny ciszy nocnej '
                .'i limity wybrane przez ludzi. Po ponownym `migrate` tabela wróciłaby pusta, czyli z domyślnym 21–8. '
                .'Jeśli to świadoma decyzja: zrób kopię (`COPY ustawienia_powiadomien_zewnetrznych TO …`), '
                .'wyczyść tabelę ręcznie (`DELETE FROM ustawienia_powiadomien_zewnetrznych`) i powtórz wycofanie.',
            );
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['push_wyslano_at', 'push_proba_at']);
        });
        Schema::dropIfExists('ustawienia_powiadomien_zewnetrznych');
        Schema::dropIfExists('push_subscriptions');
    }
};
