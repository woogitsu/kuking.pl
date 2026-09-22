<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Przepis i wszystko, co do niego należy.
 *
 * Kluczowe decyzje:
 *
 * 1. `recipe_versions` od pierwszego dnia. Przepis, który ktoś poprawił po
 *    trzech latach, nie może "zjeść" wersji, z której 40 osób gotowało.
 *
 * 2. `source_type` + `source_note` — to jest DUSZA produktu, nie metadana.
 *    "Po kim ten przepis" i "skąd go znam" odróżnia Kuking od bazy receptur.
 *    Jednocześnie jest to nasza obrona przy prawach autorskich: użytkownik
 *    deklaruje pochodzenie (docs/MODERATION.md).
 *
 * 3. `recipe_ingredients.ingredient_text` jest NOT NULL nawet gdy normalizacja
 *    nie rozpozna składnika. Wpisane przez człowieka "szklanka mąki, ta lepsza"
 *    musi przetrwać w niezmienionej formie. Normalizacja jest dodatkiem,
 *    nie warunkiem zapisu.
 *
 * 4. `recipe_slug_redirects` — zmiana tytułu nie może zabić linku, który ktoś
 *    wysłał córce SMS-em (docs/seo/SEO_TECHNICAL.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();

            $table->string('title', 180);
            $table->string('slug', 220)->unique();
            $table->string('summary', 2000)->nullable();

            $table->decimal('servings', 6, 2)->nullable();
            $table->integer('prep_minutes')->nullable();
            $table->integer('cook_minutes')->nullable();
            $table->string('difficulty', 12)->nullable();

            $table->string('visibility', 20)->default('public');
            $table->string('status', 20)->default('draft');

            $table->foreignUuid('hero_media_id')->nullable()->constrained('media')->nullOnDelete();

            // own | family | adaptation | external
            $table->string('source_type', 20)->default('own');
            $table->text('source_url')->nullable();

            // "Od kogo albo skąd masz ten przepis" — np. "od mamy",
            // "z gazety Przyjaciółka". Wolny tekst, pokazywany DOSŁOWNIE:
            // żaden widok nie ma prawa dokleić przed nim przyimka, bo odmiany
            // dowolnego ciągu znaków nie da się policzyć (Recipe::attributionLine()).
            $table->string('source_person', 120)->nullable();

            // "Skąd ten przepis" — historia, wspomnienie. Pokazywane PRZED
            // składnikami, bo to jest powód, dla którego ktoś tu wraca.
            $table->string('source_note', 2000)->nullable();

            // "W rodzinie od..." — sam rok, bez daty. Nie zbieramy więcej.
            $table->smallInteger('family_since_year')->nullable();

            // Zdjęcie kartki z zeszytu / wycinka. Bez OCR w MVP — samo zdjęcie
            // już ma wartość emocjonalną.
            $table->foreignUuid('source_scan_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE recipes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_difficulty_check CHECK (difficulty IS NULL OR difficulty IN ('easy','medium','hard'))");
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_visibility_check CHECK (visibility IN ('public','followers','private'))");
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_status_check CHECK (status IN ('draft','published','hidden','removed'))");
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_source_type_check CHECK (source_type IN ('own','family','adaptation','external'))");
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_servings_check CHECK (servings IS NULL OR servings > 0)');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_prep_check CHECK (prep_minutes IS NULL OR prep_minutes >= 0)');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_cook_check CHECK (cook_minutes IS NULL OR cook_minutes >= 0)');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_family_year_check CHECK (family_since_year IS NULL OR family_since_year BETWEEN 1850 AND 2100)');
            DB::statement('CREATE INDEX recipes_author_published_idx ON recipes (author_id, published_at DESC, id DESC) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX recipes_title_trgm_idx ON recipes USING gin (title gin_trgm_ops)');
        }

        Schema::create('recipe_slug_redirects', function (Blueprint $table): void {
            $table->string('slug', 220)->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('recipe_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('editor_id')->constrained('users')->restrictOnDelete();
            $table->integer('version_number');
            $table->jsonb('snapshot');
            $table->string('change_note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['recipe_id', 'version_number']);
        });

        Schema::create('ingredients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('canonical_name', 160);
            $table->string('normalized_name', 160)->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('name_plural', 80)->nullable();
            $table->string('unit_type', 30)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();

            // Np. "Ciasto" / "Nadzienie". Puste = jedna lista.
            $table->string('group_name', 120)->nullable();

            $table->foreignUuid('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();

            // To, co NAPRAWDĘ wpisał człowiek. Zawsze zachowane.
            $table->string('ingredient_text', 240);

            $table->decimal('quantity', 12, 4)->nullable();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->smallInteger('position');

            $table->unique(['recipe_id', 'position']);
        });

        Schema::create('recipe_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->text('instruction');
            $table->foreignUuid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->integer('timer_seconds')->nullable();

            $table->unique(['recipe_id', 'position']);
        });

        if ($this->isPostgres()) {
            foreach (['recipe_versions', 'ingredients', 'units', 'recipe_ingredients', 'recipe_steps'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
            }

            DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT recipe_versions_number_check CHECK (version_number > 0)');
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_position_check CHECK (position >= 0)');
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_check CHECK (quantity IS NULL OR quantity >= 0)');
            DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_position_check CHECK (position >= 0)');
            DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_timer_check CHECK (timer_seconds IS NULL OR timer_seconds >= 0)');
            DB::statement('CREATE INDEX ingredients_name_trgm_idx ON ingredients USING gin (normalized_name gin_trgm_ops)');
            DB::statement('CREATE INDEX recipe_ingredients_text_trgm_idx ON recipe_ingredients USING gin (ingredient_text gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('units');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipe_slug_redirects');
        Schema::dropIfExists('recipes');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
