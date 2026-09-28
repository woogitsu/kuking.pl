<?php

declare(strict_types=1);

use App\Support\Czas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proby_importu', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('zrodlo', 10);
            $table->uuid('klucz_wyslania');
            $table->foreignUuid('import_id')->nullable()->constrained('importy_przepisow')->nullOnDelete();
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->string('status', 12)->default('w_toku');
            $table->timestampTz('zgoda_ai_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->unique(['user_id', 'klucz_wyslania']);
            $table->unique('import_id');
        });

        DB::statement("ALTER TABLE proby_importu ADD CONSTRAINT proby_importu_zrodlo_check CHECK (zrodlo IN ('zdjecie', 'url', 'pdf'))");
        DB::statement("ALTER TABLE proby_importu ADD CONSTRAINT proby_importu_status_check CHECK (status IN ('w_toku', 'gotowy', 'nieudany'))");
    }

    public function down(): void
    {
        $poczatekMiesiaca = Czas::lokalnie(now())->startOfMonth()->utc();
        if (Schema::hasTable('proby_importu') && DB::table('proby_importu')->where('created_at', '>=', $poczatekMiesiaca)->exists()) {
            throw new RuntimeException('Odmawiam cofnięcia prób importu z bieżącego miesiąca: utrata księgi odnowiłaby limit 30 prób.');
        }

        Schema::dropIfExists('proby_importu');
    }
};
