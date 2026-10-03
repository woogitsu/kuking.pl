<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik „Uzupełniono zdjęcie” przy „Ugotowałem” (#2500, V2, D-333 — paczka E).
 *
 * `cooked_events.photos_added_at timestamptz NULL` — chwila, w której kucharz
 * DOŁĄCZYŁ zdjęcie do już zapisanego wykonania. Pokazuje ją publiczna karta
 * wykonania („Zdjęcie uzupełnione …”), żeby nikt nie sądził, że zdjęcie było
 * od początku, a data gotowania (`cooked_at`) zostaje nietknięta. Ustawia ją
 * wyłącznie akcja `DolaczZdjeciaDoWykonania` (poza `$fillable`). `NULL` =
 * zdjęcia nie były dołączane później (także każde wykonanie sprzed tej migracji
 * — bez backfillu).
 *
 * `ADD COLUMN … NULL` bez DEFAULT zmienia sam katalog (bez przepisania tabeli);
 * nie ma tu klucza ani CHECK-a, więc nic nie wymaga `NOT VALID` (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedno wykonanie ma znacznik: po zdjęciu
 * kolumny uzupełnione zdjęcie wyglądałoby jak oryginalne, czyli publiczna karta
 * przestałaby mówić prawdę o tym, kiedy dołożono zdjęcie. Ręcznie: zapisz mapę
 * `SELECT id, photos_added_at …`, potem wyzeruj kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS photos_added_at timestamptz NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $oznaczonych = Schema::hasColumn('cooked_events', 'photos_added_at')
            ? DB::table('cooked_events')->whereNotNull('photos_added_at')->count()
            : 0;

        if ($oznaczonych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań z dołączonym później zdjęciem '
                .'(cooked_events.photos_added_at IS NOT NULL): '.$oznaczonych.'. '
                .'Bez znacznika uzupełnione zdjęcie wyglądałoby jak oryginalne (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, photos_added_at FROM cooked_events WHERE photos_added_at IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE cooked_events DROP COLUMN IF EXISTS photos_added_at');
    }
};
