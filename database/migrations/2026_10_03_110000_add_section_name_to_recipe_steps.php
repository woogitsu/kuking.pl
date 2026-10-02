<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opcjonalna nazwa etapu przygotowania nad krokiem (#2652, decyzja właściciela
 * z 2.10.2026): „Dzień 1: farsz”, „Dzień 2: lepienie”.
 *
 * Najmniejszy model: nagłówek NIE jest osobnym wierszem, tylko polem kroku,
 * od którego zaczyna się etap (jak `recipe_ingredients.group_name` przy
 * składnikach). Etap trwa do następnego kroku z nazwą. Dzięki temu nazwanie
 * etapu nie zmienia UUID kroków (a z nimi odcisku minutnika i postępu
 * gotowania), nagłówek nie ma minutnika, zdjęcia ani własnego stanu, a numer
 * i liczba kroków dalej liczą instrukcje.
 *
 * `varchar(120) NULL`, bez DEFAULT: dodanie kolumny zmienia sam katalog.
 * CHECK (nazwa niepusta po obcięciu spacji) idzie przez `NOT VALID` +
 * `VALIDATE` (AGENTS.md §6). Brak nazwy to NULL, nigdy pusty tekst.
 *
 * ROLLBACK ODMAWIA (D-088), gdy któryś krok ma zapisaną nazwę etapu: to treść
 * autora, której `up()` nie odtworzy. Ręcznie: wyzeruj kolumnę świadomie
 * (`UPDATE recipe_steps SET section_name = NULL`) i ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('recipe_steps', function (Blueprint $table): void {
            $table->string('section_name', 120)->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_section_name_check CHECK (section_name IS NULL OR btrim(section_name) <> '') NOT VALID");
            DB::statement('ALTER TABLE recipe_steps VALIDATE CONSTRAINT recipe_steps_section_name_check');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipe_steps', 'section_name')) {
            return;
        }

        $zapisanych = DB::table('recipe_steps')->whereNotNull('section_name')->count();

        if ($zapisanych > 0) {
            throw new RuntimeException("Cofnięcie usunie nazwy etapów przygotowania z {$zapisanych} kroków. Jeśli to zamierzone, najpierw wyzeruj kolumnę świadomie: UPDATE recipe_steps SET section_name = NULL, a potem ponów rollback.");
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_steps DROP CONSTRAINT IF EXISTS recipe_steps_section_name_check');
        }

        Schema::table('recipe_steps', function (Blueprint $table): void {
            $table->dropColumn('section_name');
        });
    }
};
