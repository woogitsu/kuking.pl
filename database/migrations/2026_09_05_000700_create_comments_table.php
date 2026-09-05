<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komentarz dotyczy DOKŁADNIE JEDNEGO obiektu: wpisu, przepisu albo
 * "Ugotowałem". Pilnuje tego CHECK z num_nonnulls — nie chcemy komentarza
 * wiszącego w powietrzu ani przypiętego do dwóch rzeczy naraz.
 *
 * Odpowiedzi są jednopoziomowe w interfejsie (parent_id → wątek), bo głębokie
 * drzewa komentarzy są nieczytelne, zwłaszcza przy powiększonym tekście.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();

            $table->foreignUuid('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('cooked_event_id')->nullable()->constrained('cooked_events')->cascadeOnDelete();

            // Klucz obcy na samą siebie dodajemy poniżej, osobnym ALTER-em —
            // w jednym CREATE TABLE PostgreSQL nie widzi jeszcze własnego PK.
            $table->uuid('parent_id')->nullable();

            $table->string('body', 4000);
            $table->string('status', 20)->default('published');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
            $table->index(['parent_id']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE comments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE comments ADD CONSTRAINT comments_status_check CHECK (status IN ('published','hidden','removed'))");
            DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_single_target_check CHECK (num_nonnulls(post_id, recipe_id, cooked_event_id) = 1)');
            DB::statement('CREATE INDEX comments_post_idx ON comments (post_id, created_at)');
            DB::statement('CREATE INDEX comments_recipe_idx ON comments (recipe_id, created_at)');
            DB::statement('CREATE INDEX comments_cooked_idx ON comments (cooked_event_id, created_at)');
            DB::statement('CREATE INDEX comments_author_idx ON comments (author_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
