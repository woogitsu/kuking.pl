<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_message_replies', function (Blueprint $table): void {
            $table->uuid('reply_key')->nullable();
            $table->timestampTz('sending_started_at')->nullable();
            $table->timestampTz('audit_recorded_at')->nullable();
            $table->unique(['contact_message_id', 'reply_key'], 'contact_replies_submission_unique');
        });
        // Historycznych listów nie wolno wysłać ani audytować ponownie.
        DB::table('contact_message_replies')->update([
            'sending_started_at' => DB::raw('created_at'),
            'audit_recorded_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE contact_message_replies IN ACCESS EXCLUSIVE MODE');
        if (DB::table('contact_message_replies')->whereNotNull('reply_key')->exists()) {
            throw new RuntimeException('Pozostaw znaczniki odpowiedzi: ich usunięcie pozwoliłoby ponownie wysłać te same listy. Wycofaj sam kod, zachowując ochronę przed ponowieniem.');
        }
        Schema::table('contact_message_replies', function (Blueprint $table): void {
            $table->dropUnique('contact_replies_submission_unique');
            $table->dropColumn(['reply_key', 'sending_started_at', 'audit_recorded_at']);
        });
    }
};
