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
        Schema::table('post_tags', function (Blueprint $table): void {
            $table->boolean('dodany_recznie')->default(true);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // Obejmuje też powiązania wpisów usuniętych miękko. Blokada
            // zamyka wyścig między sprawdzeniem danych a usunięciem kolumny.
            DB::statement('LOCK TABLE post_tags IN ACCESS EXCLUSIVE MODE');
            if (DB::table('post_tags')->where('dodany_recznie', false)->exists()) {
                throw new RuntimeException('Nie można cofnąć pochodzenia tagów: istnieją tagi wyłącznie z opisu. Zachowaj kolumnę i kontrakt zapisu albo uzgodnij ręcznie sposób migracji tych danych.');
            }
            Schema::table('post_tags', function (Blueprint $table): void {
                $table->dropColumn('dodany_recznie');
            });
        });
    }
};
