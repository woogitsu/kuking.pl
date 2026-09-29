<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data ostatniej zmiany TREŚCI przepisu — źródło `dateModified` w JSON-LD (#2014).
 *
 * Ustawia ją wyłącznie `PublishRecipe`: przy pierwszej publikacji równą
 * `published_at`, potem tylko przy realnej zmianie treści albo zdjęć
 * (`App\Domain\Recipes\TrescPrzepisu`). `NULL` znaczy „nie wiemy" i wtedy
 * pola w JSON-LD po prostu nie ma — dlatego bez backfillu i bez DEFAULT:
 * zgadnięta data byłaby danymi strukturalnymi niezgodnymi z treścią.
 *
 * `ADD COLUMN … NULL` bez DEFAULT to w PostgreSQL zmiana samego katalogu,
 * bez przepisywania tabeli; `lock_timeout` dokłada `LimitBlokadMigracji`.
 *
 * ROLLBACK NIE ODMAWIA (AGENTS.md §6, D-088) i to jest świadome: kolumna
 * nie przechowuje decyzji człowieka, tylko wyliczony znacznik czasu.
 * Po `down()` i ponownym `up()` wraca jako `NULL`, czyli w stan „nie wiemy",
 * w którym strona pomija opcjonalne pole — nic nie odwraca się w stronę
 * groźną ani nieprawdziwą. Traci się tylko dokładność `dateModified` do
 * następnej zmiany treści.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->timestampTz('tresc_zmieniona_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('tresc_zmieniona_at');
        });
    }
};
