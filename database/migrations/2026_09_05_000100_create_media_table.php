<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadane zdjęć. W bazie NIE trzymamy binariów — tylko klucz obiektu
 * w object storage (docs/MEDIA_PIPELINE.md).
 *
 * `status` odzwierciedla realny cykl życia pliku:
 *   pending → processing → ready
 *                        → rejected (nie przeszło walidacji lub moderacji)
 *
 * Widok nigdy nie pokazuje zdjęcia w stanie innym niż `ready` — dzięki temu
 * użytkownik nie widzi obrazka z nieusuniętym EXIF-em ani niezweryfikowanego pliku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('disk', 40)->default('public');
            $table->string('object_key', 700)->unique();

            $table->string('mime_type', 120)->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();

            $table->string('status', 20)->default('pending');

            // Opis alternatywny. Dla dostępności bezcenny, ale NIGDY wymagany —
            // wymóg opisu zabiłby publikację "zdjęcie + kilka słów".
            $table->string('alt_text', 500)->nullable();

            $table->char('checksum_sha256', 64)->nullable();
            $table->string('perceptual_hash', 128)->nullable();

            // Wymiary wariantów, informacja o zdjętym EXIF, itp.
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();

            $table->index(['owner_id', 'created_at'], 'media_owner_created_idx');
            $table->index('checksum_sha256', 'media_checksum_idx');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE media ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE media ADD CONSTRAINT media_status_check CHECK (status IN ('pending','processing','ready','rejected','deleted'))");
            DB::statement('ALTER TABLE media ADD CONSTRAINT media_bytes_check CHECK (bytes IS NULL OR bytes >= 0)');
            DB::statement('ALTER TABLE media ADD CONSTRAINT media_width_check CHECK (width IS NULL OR width > 0)');
            DB::statement('ALTER TABLE media ADD CONSTRAINT media_height_check CHECK (height IS NULL OR height > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
