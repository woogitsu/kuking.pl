<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cooked_events ADD COLUMN photo_submission_keys jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE cooked_events ADD CONSTRAINT cooked_events_photo_submission_keys_array CHECK (jsonb_typeof(photo_submission_keys) = 'array' AND jsonb_array_length(photo_submission_keys) <= 6)");
    }

    public function down(): void
    {
        if (DB::table('cooked_events')->whereRaw("photo_submission_keys <> '[]'::jsonb")->exists()) {
            throw new RuntimeException('Nie można cofnąć historii kluczy dołączania zdjęć. Najpierw zaplanuj ręczne zachowanie tej historii, żeby ponowienie nie utworzyło zdjęć drugi raz.');
        }

        DB::statement('ALTER TABLE cooked_events DROP CONSTRAINT cooked_events_photo_submission_keys_array');
        DB::statement('ALTER TABLE cooked_events DROP COLUMN photo_submission_keys');
    }
};
