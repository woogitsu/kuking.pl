<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2023: timestamp wniosku może mieć tę samą sekundę po cofnięciu i
 * ponownym zgłoszeniu. Osobny UUID identyfikuje jego generację.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('delete_request_generation')->nullable();
        });

        // Starsze wnioski pozostają możliwe do egzekucji po wdrożeniu.
        // PostgreSQL nadaje osobny UUID każdemu wierszowi w jednym UPDATE.
        if (DB::getDriverName() === 'pgsql') {
            DB::table('users')
                ->where('status', 'pending_delete')
                ->whereNull('data_erased_at')
                ->whereNotNull('delete_requested_at')
                ->whereNull('delete_request_generation')
                ->update(['delete_request_generation' => DB::raw('gen_random_uuid()')]);
        }
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('delete_request_generation')->exists()) {
            throw new RuntimeException('Nie cofam identyfikatora wniosków, gdy trwa choć jeden wniosek o usunięcie konta.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('delete_request_generation');
        });
    }
};
