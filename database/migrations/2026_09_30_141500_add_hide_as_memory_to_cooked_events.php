<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wspomnienia z własnych wykonań (F6, research z 30 września 2026).
 *
 * `cooked_events.hide_as_memory` — ukrycie JEDNEGO wykonania w bloku
 * „Rok temu…” na stronie głównej, bliźniacze do `posts.hide_as_memory`
 * (migracja `2026_09_06_140000_add_memories_to_users_and_posts`). Wykonanie
 * zostaje na profilu i pod przepisem; znika wyłącznie ze wspomnień.
 *
 * Kolumna na `cooked_events`, a nie wspólna tabela ukryć, z tego samego
 * powodu co przy wpisach: wspomnienie to zawsze WŁASNE wykonanie
 * oglądającego, więc osoba ukrywająca i kucharz to ta sama osoba.
 *
 * DDL: `ADD COLUMN … NOT NULL DEFAULT false` ze stałą wartością domyślną
 * PostgreSQL (od 11) zapisuje w katalogu, bez przepisywania tabeli — krótka
 * blokada, którą i tak ogranicza `lock_timeout` migratora (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA, GDY KTOŚ COŚ SCHOWAŁ (D-088). Stary schemat nie ma tej
 * kolumny, a kolejny `migrate` odtworzyłby ją z `DEFAULT false`, czyli
 * schowane wykonanie po przepisie po zmarłej osobie wróciłoby na stronę
 * główną. Przy samych wartościach domyślnych i na świeżej bazie cofnięcie
 * przechodzi bez pytania (`CofniecieMigracjiUkryciaWykonanTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooked_events', function (Blueprint $table): void {
            $table->boolean('hide_as_memory')->default(false);
        });
    }

    public function down(): void
    {
        // Strażnik PRZED `dropColumn` — po zdjęciu kolumny nie ma czego liczyć.
        $schowane = DB::table('cooked_events')->where('hide_as_memory', true)->count();

        if ($schowane > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań schowanych ze wspomnień '.
                '(cooked_events.hide_as_memory = true): '.$schowane.'. To decyzja człowieka o tym, '.
                'czego NIE chce widzieć na stronie głównej. Kolejny `migrate` odtworzyłby kolumnę '.
                'z `DEFAULT false` i schowane wykonanie wróciłoby jako wspomnienie (D-088).'."\n\n".
                "CO ZROBIĆ:\n".
                '  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '.
                "kolumny nie zna i działa z nią bez zmian;\n".
                "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę PRZED cofnięciem:\n".
                "      SELECT id FROM cooked_events WHERE hide_as_memory = true;\n".
                '    a po powrocie na tę wersję schematu odtwórz ją tym samym `UPDATE`, zanim '.
                'strona główna pokaże komukolwiek wspomnienie.',
            );
        }

        Schema::table('cooked_events', function (Blueprint $table): void {
            $table->dropColumn('hide_as_memory');
        });
    }
};
