<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `data_exports.notified_at` — kiedy list „paczka gotowa" został przyjęty
 * przez transport (issue #820).
 *
 * Do tej migracji list wysyłał sam `GenerateUserExport`, raz, bez ponowienia:
 * chwilowa awaria poczty zostawiała paczkę gotową, a człowieka bez
 * wiadomości. Teraz wysyła go osobne zadanie `NotifyUserExportReady`
 * z ponowieniami, a ta kolumna jest jego zamkiem: zadanie ZAJMUJE list
 * warunkowym `UPDATE … WHERE notified_at IS NULL`, więc drugi przebieg
 * (ponowne doręczenie z kolejki, duplikat) listu nie wyśle.
 *
 * BACKFILL: paczki gotowe przed tą migracją dostają `notified_at =
 * completed_at`. Stary kod próbował wysłać list w tym samym przebiegu, który
 * ustawił `ready` — nie wiemy, czy się udało, ale wiemy, że próba była
 * i że nikt jej już nie ponowi. Pusta kolumna na tych wierszach kazałaby
 * ekranowi ustawień mówić „e-mail jeszcze nie wyszedł" o listach sprzed
 * tygodnia, czyli zgadywać w drugą stronę.
 *
 * ROLLBACK: `down()` usuwa kolumnę i CHECK bez odmowy. To nie jest wartość
 * semantyczna w rozumieniu D-088 (niczyja decyzja, zgoda ani zakres): po
 * ponownym `up()` backfill oznacza gotowe paczki jako powiadomione, więc
 * cykl down/up może co najwyżej ZGUBIĆ list czekający w kolejce — nigdy nie
 * wyśle drugiego. Przed wycofaniem kodu zatrzymaj workery; zadania
 * `NotifyUserExportReady` pozostałe w `jobs` po powrocie do starego kodu
 * padną na brakującej klasie i trafią do `failed_jobs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->timestampTz('notified_at')->nullable();
        });

        DB::table('data_exports')
            ->whereIn('status', ['ready', 'expired'])
            ->whereNotNull('completed_at')
            ->update(['notified_at' => DB::raw('completed_at')]);

        // List o paczce, która nigdy nie była gotowa, nie istnieje.
        DB::statement('ALTER TABLE data_exports ADD CONSTRAINT data_exports_notified_after_completed_check '
            .'CHECK (notified_at IS NULL OR completed_at IS NOT NULL)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('data_exports') || ! Schema::hasColumn('data_exports', 'notified_at')) {
            return;
        }

        DB::statement('ALTER TABLE data_exports DROP CONSTRAINT IF EXISTS data_exports_notified_after_completed_check');

        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
