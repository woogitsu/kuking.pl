<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wybór redakcyjny na dany dzień — „kuKINGi na dziś".
 *
 * To NIE jest tabela rankingowa i nigdy nią nie będzie. Nie ma tu kolumny
 * z punktami, liczbą polubień ani wynikiem. Jest data, jest kolejność
 * wyświetlania i jest osoba, która to wybrała — bo za wyborem stoi człowiek,
 * nie algorytm (AGENTS.md: publiczne rankingi dzielą ludzi na dwie klasy
 * i wyłączają publikowanie u większości).
 *
 * Gdy na dany dzień nie ma wyboru redakcyjnego, tablica dobiera treści
 * chronologicznie — patrz App\Domain\Feed\DailyBoard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_picks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Sam dzień, bez godziny. Tablica zmienia się raz na dobę.
            $table->date('shown_on');

            // 'user' albo 'post' — tablica pokazuje ludzi i dania obok siebie.
            $table->string('subject_type', 20);
            $table->uuid('subject_id');

            $table->smallInteger('position')->default(0);

            // Kto wybrał. Zostaje po to, żeby dało się zapytać „dlaczego to?",
            // a nie po to, żeby liczyć czyjeś zasługi.
            $table->foreignUuid('curator_id')->nullable()->constrained('users')->nullOnDelete();

            // Opcjonalne jedno zdanie od gospodarza: „Halina pierwszy raz
            // pokazała swój chleb". Wyświetlane pod kartą.
            $table->string('note', 300)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['shown_on', 'subject_type', 'subject_id']);
            $table->index(['shown_on', 'position']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE daily_picks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE daily_picks ADD CONSTRAINT daily_picks_subject_type_check CHECK (subject_type IN ('user','post'))");
            DB::statement('ALTER TABLE daily_picks ADD CONSTRAINT daily_picks_position_check CHECK (position >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_picks');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
