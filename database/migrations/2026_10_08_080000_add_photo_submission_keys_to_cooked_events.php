<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Krótkie ADD i późniejsze VALIDATE nie mogą zatrzymać tej samej blokady.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS photo_submission_keys jsonb NOT NULL DEFAULT '[]'::jsonb");
        if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'cooked_events'::regclass AND conname = ?", ['cooked_events_photo_submission_keys_array']) === null) {
            DB::statement("ALTER TABLE cooked_events ADD CONSTRAINT cooked_events_photo_submission_keys_array CHECK (jsonb_typeof(photo_submission_keys) = 'array') NOT VALID");
        }
        DB::statement('ALTER TABLE cooked_events VALIDATE CONSTRAINT cooked_events_photo_submission_keys_array');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cooked_events', 'photo_submission_keys')) {
            return;
        }
        if (DB::table('cooked_events')->whereRaw("photo_submission_keys <> '[]'::jsonb")->exists()) {
            throw new RuntimeException('Nie można cofnąć historii kluczy dołączania zdjęć. Najpierw zaplanuj ręczne zachowanie tej historii, żeby ponowienie nie utworzyło zdjęć drugi raz.');
        }

        DB::statement('ALTER TABLE cooked_events DROP CONSTRAINT IF EXISTS cooked_events_photo_submission_keys_array');
        DB::statement('ALTER TABLE cooked_events DROP COLUMN photo_submission_keys');
    }
};
