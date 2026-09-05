<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wpis — najniższy próg publikacji w Kuking: zdjęcie + kilka słów.
 * Bez składników, bez kategorii, bez kroków. To jest główna akcja produktu.
 *
 * Indeks częściowy na (author_id, published_at DESC, id DESC) obsługuje
 * jednocześnie archiwum profilu i feed obserwowanych — feed MVP to zwykłe
 * WHERE author_id IN (...) ORDER BY published_at DESC, id DESC z kursorem.
 * Żadnego fanout-on-write (docs/ARCHITECTURE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();

            $table->string('body', 4000)->nullable();

            $table->string('visibility', 20)->default('public');
            $table->string('status', 20)->default('draft');

            // Jeśli wpis jest wykonaniem cudzego przepisu, wskazujemy przepis.
            // Osobna encja `cooked_events` jest dla "Ugotowałem" z pełnym
            // kontekstem; tutaj to tylko luźne powiązanie.
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE posts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_visibility_check CHECK (visibility IN ('public','followers','private'))");
            DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_status_check CHECK (status IN ('draft','published','hidden','removed'))");
            DB::statement('CREATE INDEX posts_author_published_idx ON posts (author_id, published_at DESC, id DESC) WHERE deleted_at IS NULL');
            DB::statement("CREATE INDEX posts_published_idx ON posts (published_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND visibility = 'public'");
        }

        Schema::create('post_media', function (Blueprint $table): void {
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);

            $table->primary(['post_id', 'media_id']);
            $table->unique(['post_id', 'position']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE post_media ADD CONSTRAINT post_media_position_check CHECK (position >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
