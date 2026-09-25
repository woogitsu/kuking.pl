<?php

declare(strict_types=1);

use App\Support\Odmiana;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `zalegle_czyszczenia_cdn` — adresy, których cache CDN jeszcze NIE
 * wyczyszczono (issue #959).
 *
 * Do tej migracji `PurgePublicMediaCache` bez `CLOUDFLARE_ZONE_ID` albo
 * `CLOUDFLARE_PURGE_TOKEN` zapisywał ostrzeżenie i kończył się sukcesem.
 * Adresy skasowanych zdjęć przepadały: po uzupełnieniu konfiguracji nikt nie
 * wiedział, co trzeba wyczyścić. Teraz na produkcji trafiają tutaj, a
 * `kuking:wyczysc-zalegle-cdn` (co kwadrans) wysyła je, gdy konfiguracja
 * wróci. Tu lądują też adresy zadania, które wyczerpało próby.
 *
 * `adres` jest UNIKALNY: czyszczenie jest idempotentne, więc drugie
 * odłożenie tego samego adresu nie ma nic dodawać.
 *
 * ROLLBACK ODMAWIA przy niepustej tabeli (D-088). Każdy wiersz to skasowane
 * zdjęcie, które może się jeszcze otwierać z cache — często po wymazaniu
 * konta albo decyzji moderacyjnej. Zrzucenie tabeli zgubiłoby tę listę bez
 * śladu. Pusta tabela znika bez pytań.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalegle_czyszczenia_cdn', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('adres', 2048)->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE zalegle_czyszczenia_cdn ADD CONSTRAINT zalegle_czyszczenia_cdn_adres_http_check '
            ."CHECK (adres ~ '^https?://')");
    }

    public function down(): void
    {
        if (! Schema::hasTable('zalegle_czyszczenia_cdn')) {
            return;
        }

        $ile = DB::table('zalegle_czyszczenia_cdn')->count();

        if ($ile > 0) {
            throw new RuntimeException(
                "W `zalegle_czyszczenia_cdn` czeka na wyczyszczenie z cache CDN {$ile} "
                .Odmiana::rzeczownik($ile, 'adres', 'adresy', 'adresów').'. '
                .'Uzupełnij CLOUDFLARE_ZONE_ID i CLOUDFLARE_PURGE_TOKEN, uruchom '
                .'`php artisan kuking:wyczysc-zalegle-cdn` i powtórz wycofanie, gdy tabela będzie pusta. '
                .'Nie kasuję jej sam: to są skasowane zdjęcia, które mogą się jeszcze otwierać.',
            );
        }

        Schema::drop('zalegle_czyszczenia_cdn');
    }
};
