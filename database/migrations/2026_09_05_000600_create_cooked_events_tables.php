<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Ugotowałem" — najważniejsza encja w całym Kuking.
 *
 * To realne wykonanie CZYJEGOŚ przepisu. Silniejszy sygnał jakości niż lajk,
 * bo wymaga, żeby ktoś naprawdę stanął przy garnku.
 *
 * ŻADNEGO unique (user_id, recipe_id). Ta sama osoba gotuje ten sam przepis
 * co dwa tygodnie od dziesięciu lat i każde takie wykonanie ma wartość.
 * Jeśli kiedyś ktoś doda tu unikalność, zepsuje sedno produktu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooked_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();

            $table->string('note', 2000)->nullable();
            $table->boolean('would_make_again')->nullable();
            $table->string('perceived_difficulty', 12)->nullable();
            $table->integer('actual_minutes')->nullable();

            // Co zmieniłem po swojemu — pierwszy krok do "Mojej wersji" (V1),
            // ale już teraz najczęściej czytana część komentarza pod przepisem.
            $table->string('changes_note', 1000)->nullable();

            $table->timestampTz('cooked_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('cooked_event_media', function (Blueprint $table): void {
            $table->foreignUuid('cooked_event_id')->constrained('cooked_events')->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);

            $table->primary(['cooked_event_id', 'media_id']);
            $table->unique(['cooked_event_id', 'position']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE cooked_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE cooked_events ADD CONSTRAINT cooked_events_difficulty_check CHECK (perceived_difficulty IS NULL OR perceived_difficulty IN ('easy','medium','hard'))");
            DB::statement('ALTER TABLE cooked_events ADD CONSTRAINT cooked_events_minutes_check CHECK (actual_minutes IS NULL OR actual_minutes >= 0)');
            DB::statement('ALTER TABLE cooked_event_media ADD CONSTRAINT cooked_event_media_position_check CHECK (position >= 0)');
            DB::statement('CREATE INDEX cooked_events_recipe_idx ON cooked_events (recipe_id, cooked_at DESC)');
            DB::statement('CREATE INDEX cooked_events_user_idx ON cooked_events (user_id, cooked_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cooked_event_media');
        Schema::dropIfExists('cooked_events');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
