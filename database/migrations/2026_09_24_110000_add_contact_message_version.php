<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(0);
        });
    }

    public function down(): void
    {
        // Licznik techniczny: wycofanie nie zmienia notatki ani stanu.
        // Przy ponownym wdrożeniu trzeba unieważnić otwarte formularze.
        Schema::table('contact_messages', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
