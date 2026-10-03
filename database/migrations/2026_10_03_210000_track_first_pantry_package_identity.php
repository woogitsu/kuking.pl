<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NULL oznacza pierwotne pierwsze opakowanie (jego tożsamością jest id produktu).
 * Po awansie drugiego zachowujemy jego UUID także po usunięciu osobnego wiersza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pantry_items', function (Blueprint $table): void {
            $table->uuid('first_package_id')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pantry_items', 'first_package_id')) {
            return;
        }

        // Po awansie nie da się odtworzyć tożsamości B z pozostałych pól.
        if (DB::table('pantry_items')->whereNotNull('first_package_id')->exists()) {
            throw new RuntimeException('Nie można cofnąć identyfikatora pierwszego opakowania: co najmniej jedno drugie opakowanie awansowało na jego miejsce. Zachowaj kolumnę albo ręcznie zaplanuj odtworzenie tożsamości z kopii danych.');
        }

        Schema::table('pantry_items', function (Blueprint $table): void {
            $table->dropColumn('first_package_id');
        });
    }
};
