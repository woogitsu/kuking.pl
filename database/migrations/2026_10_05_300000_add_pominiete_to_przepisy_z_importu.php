<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `przepisy_z_importu.pominiete` — co import pominął lub uciął (#2521).
 *
 * Decyzja właściciela z 2.10.2026: import przepisu dłuższego niż limity
 * formularza (120 składników, 60 kroków, długość pól) kończy się szkicem
 * Z OSTRZEŻENIEM widocznym przy każdym otwarciu — nic nie znika po cichu.
 *
 * Kolumna trzyma WYŁĄCZNIE liczby i nazwy pól, np.
 * `{"skladniki": 3, "kroki": 0, "obciete": ["krok:2", "tytul"]}` — bez treści
 * przepisu i bez tekstu ze źródła, więc polityka prywatności się nie zmienia.
 * `NULL` = import kompletny (albo wiersz sprzed tej migracji). Bez CHECK:
 * dodanie ograniczenia do istniejącej tabeli wymagałoby wzorca NOT VALID +
 * VALIDATE (AGENTS.md §6), a kształt pilnuje `PominieteWImporcie::zTablicy()`.
 *
 * ROLLBACK: `down()` ODMAWIA, gdy istnieje niesprawdzony szkic z niepełnym
 * importem (D-088) — po zdjęciu kolumny wyglądałby jak kompletny i dałoby się
 * go opublikować bez wiedzy, że brakuje mu końcowych pozycji. Przy samych
 * szkicach już opublikowanych albo bez ostrzeżeń rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('przepisy_z_importu', function (Blueprint $table): void {
            $table->jsonb('pominiete')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('przepisy_z_importu', 'pominiete')) {
            $ile = DB::table('przepisy_z_importu')
                ->whereNotNull('pominiete')
                ->whereNull('sprawdzone_at')
                ->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Odmawiam cofnięcia migracji: w przepisy_z_importu są niesprawdzone szkice z niepełnym '
                    ."importem (pominiete niepuste, sprawdzone_at puste). Liczba: {$ile}. Bez tej kolumny "
                    .'wyglądałyby na kompletne. CO ZROBIĆ: poproś autorów o sprawdzenie i publikację albo '
                    .'usunięcie tych szkiców, zachowaj kopię (SELECT recipe_id, pominiete FROM przepisy_z_importu '
                    .'WHERE pominiete IS NOT NULL) i dopiero wtedy cofnij migrację.',
                );
            }
        }

        Schema::table('przepisy_z_importu', function (Blueprint $table): void {
            $table->dropColumn('pominiete');
        });
    }
};
