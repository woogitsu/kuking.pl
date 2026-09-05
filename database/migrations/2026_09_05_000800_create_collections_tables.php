<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolekcje = osobisty zeszyt. Domyślnie PRYWATNE.
 *
 * Ktoś, kto zapisuje przepis "na potem", nie ogłasza tego światu. Publiczna
 * kolekcja jest świadomą decyzją, nie ustawieniem domyślnym.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('visibility', 20)->default('private');

            // Kolekcja tworzona automatycznie przy pierwszym "Zapisuję".
            // Nie zmuszamy nikogo do wymyślania nazwy folderu przed zapisem.
            $table->boolean('is_default')->default(false);

            $table->timestampsTz();

            $table->index(['owner_id', 'created_at']);
        });

        Schema::create('collection_items', function (Blueprint $table): void {
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['collection_id', 'recipe_id']);
            $table->index(['recipe_id']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE collections ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_visibility_check CHECK (visibility IN ('public','private'))");
            DB::statement('CREATE UNIQUE INDEX collections_one_default_per_owner_idx ON collections (owner_id) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
