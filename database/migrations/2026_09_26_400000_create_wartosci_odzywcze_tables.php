<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * WARTOŚCI ODŻYWCZE — TABELA SKŁADNIKÓW, MIARY DOMOWE, SŁOWNIK NAZW (V2, D-299).
 *
 * Trzy tabele słownikowe, które wypełnia WYŁĄCZNIE komenda
 * `kuking:importuj-wartosci-odzywcze` z plików w repozytorium
 * (`database/data/odzywcze/`). Człowiek przy przepisie niczego tu nie wpisuje
 * i żadna trasa HTTP do nich nie pisze. Nie ma pobierania z sieci
 * (decyzja właściciela z 26.09.2026).
 *
 *  - `skladniki_odzywcze` — pozycja z otwartej tabeli (CIQUAL 2025 albo USDA
 *    FoodData Central SR Legacy) z wartościami na 100 g i opcjonalną
 *    gęstością do przeliczania mililitrów;
 *  - `miary_domowe` — „1 szklanka mąki pszennej = 140 g”, „1 cebula = 110 g”.
 *    Gęstość zależy od składnika, więc miara jest per składnik, a nie
 *    w `units` (test „szklanka mąki” ≠ „szklanka cukru”);
 *  - `aliasy_skladnikow` — polskie nazwy i ich formy („mąki pszennej”,
 *    „jajek”) po normalizacji `Ingredient::normalize()`.
 *
 * DLACZEGO NIE KLUCZ OBCY DO `ingredients`, JAK W SZKICU PROJEKTU
 * `ingredients` rośnie z tego, co ludzie wpisują, i dostaje CAŁY tekst
 * wiersza („2 szklanki mąki pszennej” to osobne hasło od „mąka pszenna”).
 * Dopasowanie do tabeli wartości przez to hasło wymagałoby przypięcia
 * tysięcy haseł ręcznie. Słownik aliasów robi to raz, dla wszystkich form.
 *
 * WYCOFANIE: `down()` zdejmuje trzy tabele. Nic tu nie jest decyzją
 * człowieka ani treścią użytkownika — to kopia plików z repozytorium,
 * którą komenda importu odtwarza w całości. Cofnięcie jest bezstratne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skladniki_odzywcze', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('klucz', 80)->unique();
            $table->string('nazwa', 120);
            $table->string('zrodlo', 10);
            $table->string('zrodlo_id', 20);
            $table->string('zrodlo_nazwa', 200);
            $table->decimal('kcal_100g', 7, 2);
            $table->decimal('bialko_100g', 6, 2);
            $table->decimal('tluszcz_100g', 6, 2);
            $table->decimal('weglowodany_100g', 6, 2);
            $table->decimal('gestosc_g_ml', 5, 3)->nullable();
            $table->boolean('pomijalny')->default(false);
            $table->timestampsTz();
        });

        Schema::create('miary_domowe', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('skladnik_odzywczy_id')->constrained('skladniki_odzywcze')->cascadeOnDelete();
            $table->string('jednostka', 30);
            $table->decimal('gramy', 8, 2);
            $table->string('uwagi', 200)->nullable();
            $table->unique(['skladnik_odzywczy_id', 'jednostka']);
        });

        Schema::create('aliasy_skladnikow', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('alias', 240)->unique();
            $table->foreignUuid('skladnik_odzywczy_id')->constrained('skladniki_odzywcze')->cascadeOnDelete();
            $table->index('skladnik_odzywczy_id');
        });

        foreach (['skladniki_odzywcze', 'miary_domowe', 'aliasy_skladnikow'] as $tabela) {
            DB::statement("ALTER TABLE {$tabela} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
        }

        DB::statement("ALTER TABLE skladniki_odzywcze ADD CONSTRAINT skladniki_odzywcze_zrodlo_check
            CHECK (zrodlo IN ('ciqual', 'usda'))");
        DB::statement("ALTER TABLE skladniki_odzywcze ADD CONSTRAINT skladniki_odzywcze_klucz_check
            CHECK (klucz ~ '^[a-z0-9_]+$')");
        // Energia na 100 g nie przekracza tłuszczu czystego (ok. 900 kcal);
        // 950 zostawia margines na zaokrąglenia tabel.
        DB::statement('ALTER TABLE skladniki_odzywcze ADD CONSTRAINT skladniki_odzywcze_kcal_check
            CHECK (kcal_100g >= 0 AND kcal_100g <= 950)');
        DB::statement('ALTER TABLE skladniki_odzywcze ADD CONSTRAINT skladniki_odzywcze_makro_check
            CHECK (bialko_100g BETWEEN 0 AND 100 AND tluszcz_100g BETWEEN 0 AND 100 AND weglowodany_100g BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE skladniki_odzywcze ADD CONSTRAINT skladniki_odzywcze_gestosc_check
            CHECK (gestosc_g_ml IS NULL OR (gestosc_g_ml > 0 AND gestosc_g_ml < 3))');
        DB::statement("ALTER TABLE miary_domowe ADD CONSTRAINT miary_domowe_jednostka_check
            CHECK (jednostka ~ '^[a-z]+$')");
        DB::statement('ALTER TABLE miary_domowe ADD CONSTRAINT miary_domowe_gramy_check
            CHECK (gramy > 0 AND gramy <= 10000)');
        DB::statement("ALTER TABLE aliasy_skladnikow ADD CONSTRAINT aliasy_skladnikow_alias_check
            CHECK (btrim(alias) <> '' AND alias = lower(alias))");
    }

    public function down(): void
    {
        Schema::dropIfExists('aliasy_skladnikow');
        Schema::dropIfExists('miary_domowe');
        Schema::dropIfExists('skladniki_odzywcze');
    }
};
