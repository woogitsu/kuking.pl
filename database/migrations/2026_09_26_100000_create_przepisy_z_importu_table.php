<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pochodzenie szkicu przepisu z importu (D-300).
 *
 * Jeden wiersz na przepis, który powstał z importu (adres strony, PDF, zdjęcie).
 * To NIE jest dziennik zleceń importu (status, koszt, odpowiedź modelu — ten
 * żyje w tabeli importów fundamentu i ma retencję 90 dni). Ten wiersz żyje tyle,
 * co przepis, bo pilnuje dwóch rzeczy przy PUBLIKACJI:
 *
 *  - `sprawdzone_at` — człowiek zaznaczył „Sprawdziłem odczytany tekst";
 *    bez tego szkic z importu się nie opublikuje (decyzja właściciela 26.09);
 *  - `tekst_zrodla` — kroki w brzmieniu ze źródła, do ostrzeżenia „opis prawie
 *    taki sam jak na stronie" (pg_trgm). Czyszczony przy publikacji
 *    (minimalizacja: po publikacji nie jest już potrzebny).
 *
 * Źródło z adresu jest OBOWIĄZKOWE: CHECK wymusza `source_url` przy `zrodlo = url`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('przepisy_z_importu', function (Blueprint $table): void {
            $table->foreignUuid('recipe_id')->primary()->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('zrodlo');
            $table->text('droga');
            $table->text('source_url')->nullable();
            $table->text('tekst_zrodla')->nullable();
            $table->timestampTz('sprawdzone_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE przepisy_z_importu ADD CONSTRAINT przepisy_z_importu_zrodlo_check
            CHECK (zrodlo IN ('url', 'pdf', 'zdjecie'))");
        DB::statement("ALTER TABLE przepisy_z_importu ADD CONSTRAINT przepisy_z_importu_droga_check
            CHECK (droga IN ('json_ld', 'fragmenty', 'tekst_pdf', 'ocr', 'bez_tresci'))");
        DB::statement("ALTER TABLE przepisy_z_importu ADD CONSTRAINT przepisy_z_importu_url_check
            CHECK (zrodlo <> 'url' OR (source_url IS NOT NULL AND source_url ~* '^https?://'))");
        DB::statement('ALTER TABLE przepisy_z_importu ADD CONSTRAINT przepisy_z_importu_url_dlugosc_check
            CHECK (source_url IS NULL OR char_length(source_url) <= 2000)');
    }

    /**
     * ODMAWIA, gdy istnieje niesprawdzony szkic z importu (D-088).
     *
     * Po cofnięciu i ponownym `migrate` taki szkic wyglądałby jak zwykły,
     * wpisany ręcznie — dałoby się go opublikować bez „Sprawdziłem odczytany
     * tekst" i bez ostrzeżenia o podobieństwie do cudzej strony. Na świeżej
     * bazie i przy samych sprawdzonych wierszach rollback przechodzi.
     */
    public function down(): void
    {
        if (Schema::hasTable('przepisy_z_importu')) {
            $ile = DB::table('przepisy_z_importu')->whereNull('sprawdzone_at')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Odmawiam cofnięcia migracji: w przepisy_z_importu są szkice z importu, których autor '
                    ."jeszcze nie sprawdził (sprawdzone_at puste). Liczba: {$ile}. Bez tej tabeli dałoby się "
                    .'je opublikować bez potwierdzenia „Sprawdziłem odczytany tekst”. CO ZROBIĆ: wycofaj najpierw '
                    .'kod importu (KUKING_IMPORT_URL=false, KUKING_IMPORT_PDF=false), zachowaj kopię '
                    .'(SELECT * FROM przepisy_z_importu) i dopiero wtedy — świadomie — usuń tabelę ręcznie.',
                );
            }
        }

        Schema::dropIfExists('przepisy_z_importu');
    }
};
