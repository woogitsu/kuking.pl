<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `zgoda_potwierdzona_at` — kiedy człowiek ostatni raz wszedł przez
 * dostawcę, czyli ostatni raz potwierdził nam dostęp (issue #1025).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA KOLUMNA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Powiadomienie Facebooka o odebraniu dostępu ma poprawny podpis bez końca.
 * Bez granicy czasu to samo, stare powiadomienie dostarczone PO ponownej
 * zgodzie usypiało powiązanie jeszcze raz — nadpisując nowszą decyzję
 * człowieka. Kontroler porównuje `issued_at` z tą kolumną i starsze
 * wiadomości zostawia bez skutku.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE `connected_at`
 * ────────────────────────────────────────────────────────────────────────
 *
 * `connected_at` odpowiada na pytanie „od kiedy to konto jest połączone"
 * i jest cechą konta, której `audit_log` nie utrzyma (docs/DATABASE.md).
 * Przepisywanie go przy każdym wejściu skasowałoby tę odpowiedź. Pusta
 * `zgoda_potwierdzona_at` znaczy „od założenia powiązania nie było
 * ponownego wejścia" — i wtedy granicą jest właśnie `connected_at`.
 */
return new class extends Migration
{
    private const TABELA = 'tozsamosci_zewnetrzne';

    private const KOLUMNA = 'zgoda_potwierdzona_at';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA) || Schema::hasColumn(self::TABELA, self::KOLUMNA)) {
            return;
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $table->timestampTz(self::KOLUMNA)->nullable();
        });
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY JEST CO STRACIĆ (D-088).
     *
     * Po `down()` i kolejnym `migrate` kolumna wraca PUSTA, granicą znów
     * staje się `connected_at`, a stare powiadomienia o odebraniu dostępu
     * — wystawione przed ponowną zgodą — znów zaczynają działać. Nie ma
     * przy tym żadnego błędu do zauważenia. Pusta kolumna niczego nie traci
     * i cofa się bez pytania.
     *
     * UWAGA NA `migrate:rollback --step 1`: cofa migrację NAJPÓŹNIEJSZĄ,
     * niekoniecznie tę. Żeby cofnąć właśnie ją, wołaj `down()` wprost.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABELA) || ! Schema::hasColumn(self::TABELA, self::KOLUMNA)) {
            return;
        }

        $zapisane = (int) DB::table(self::TABELA)->whereNotNull(self::KOLUMNA)->count();

        if ($zapisane > 0 && ! $this->wolnoSkasowacGranice()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby czas ostatniej zgody na połączenie konta '
                .'z dostawcą. Liczba powiązań, których to dotyczy: '.$zapisane.".\n\n"
                ."CZYM TO GROZI\n"
                .'Po ponownym `migrate` kolumna wróci pusta, a stare powiadomienia Facebooka '
                .'o odebraniu dostępu — wystawione przed ponowną zgodą — znów będą mogły '
                ."uśpić te powiązania.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_GRANICE_ZGODY=true '
                .'php artisan migrate:rollback',
            );
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $table->dropColumn(self::KOLUMNA);
        });
    }

    /** `getenv()`, a nie `env()` — jak w pozostałych migracjach z tym strażnikiem. */
    private function wolnoSkasowacGranice(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_GRANICE_ZGODY'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
};
