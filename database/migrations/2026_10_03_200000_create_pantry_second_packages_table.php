<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drugie opakowanie tego samego produktu w „Co mam w domu” (#2568, V2).
 *
 * CO TU JEST
 * Tabela `pantry_second_packages`: najwyżej JEDEN wiersz na produkt
 * (`UNIQUE (pantry_item_id)`) z własnym terminem, rodzajem terminu, ilością
 * (wolny tekst) i oznaczeniem „mrożone”. Pierwsze opakowanie to nadal te
 * kolumny w `pantry_items` — dotychczasowe terminy i ilości nie są ruszane,
 * migracja niczego nie przenosi ani nie przelicza.
 *
 * DLACZEGO NIE „zdejmijmy UNIQUE (user_id, klucz)”. Nazwa produktu zostaje
 * jedna na osobę (D-285): „jajko” nadal nie tworzy drugiego wpisu obok
 * „Jajek”, a zwykłe dodanie tej samej nazwy nadal nie tworzy niczego nowego.
 * Drugie opakowanie powstaje wyłącznie jawną akcją („Dodaj drugie
 * opakowanie”). Limit „dwa opakowania” stoi na UNIQUE w bazie, więc dwa
 * równoległe zapisy nie wyprodukują trzeciego; limit 150 produktów dotyczy
 * produktów, a nie opakowań.
 *
 * CHECK-i takie same jak dla pierwszego opakowania (migracja
 * `2026_10_01_101500_add_expiry_to_pantry_items`). Nowa, pusta tabela nie
 * potrzebuje wzorca `NOT VALID`.
 *
 * PRYWATNOŚĆ. Wiersz należy do produktu, a ten do jednej osoby; nie ma
 * własnego `user_id`. Znika kaskadą razem z produktem (usunięcie produktu,
 * wymazanie konta usuwa `pantry_items`). Jest w paczce danych
 * (`co_mam_w_domu[].drugie_opakowanie`).
 *
 * ROLLBACK (D-088, odmowa WĄSKA). `down()` odmawia, gdy istnieje choć jedno
 * drugie opakowanie: to osobna decyzja człowieka (osobny termin, ilość,
 * zamrożenie), której nie wolno scalić po cichu z pierwszym opakowaniem ani
 * skasować. Komunikat mówi, co zrobić. Na świeżej bazie cofnięcie przechodzi
 * bez pytania. Wymuszenie po kopii: `KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_second_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pantry_item_id')->constrained('pantry_items')->cascadeOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('expiry_kind', 12)->nullable();
            $table->string('quantity_note', 40)->nullable();
            $table->boolean('frozen')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('pantry_item_id');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE pantry_second_packages ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE pantry_second_packages ADD CONSTRAINT pantry_second_packages_expiry_kind_check CHECK (expiry_kind IS NULL OR expiry_kind IN ('use_by', 'best_before'))");
            DB::statement('ALTER TABLE pantry_second_packages ADD CONSTRAINT pantry_second_packages_expiry_pair_check CHECK ((expires_on IS NULL) = (expiry_kind IS NULL))');
            DB::statement("ALTER TABLE pantry_second_packages ADD CONSTRAINT pantry_second_packages_expires_on_range_check CHECK (expires_on IS NULL OR expires_on BETWEEN DATE '2020-01-01' AND DATE '2100-12-31')");
            DB::statement('ALTER TABLE pantry_second_packages ADD CONSTRAINT pantry_second_packages_quantity_note_check CHECK (quantity_note IS NULL OR char_length(btrim(quantity_note)) BETWEEN 1 AND 40)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pantry_second_packages')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('pantry_second_packages');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('pantry_second_packages')->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje drugie opakowania produktów z list „Co mam w domu” — bezpowrotnie.
            Każde z nich ma własny termin, ilość i oznaczenie „mrożone”; nie da się ich scalić z pierwszym opakowaniem bez wybrania za człowieka, który termin wygrywa.
            Liczba drugich opakowań, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE pantry_second_packages_kopia AS SELECT * FROM pantry_second_packages;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu naprawia się bez ruszania bazy
                 (tabela zostaje, kod ją ignoruje);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA=1.

            Na świeżym środowisku, gdzie nikt jeszcze nie dodał drugiego opakowania, cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
