<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prywatny dzień faktycznego gotowania przy „Ugotowałem" (issue #2583,
 * decyzja właściciela z 2.10.2026).
 *
 * `cooked_events.dzien_gotowania date NULL` — dzień KALENDARZOWY, który
 * kucharz świadomie podał, gdy zgłasza wykonanie później niż gotował.
 * Osobny od `cooked_at` (chwila zgłoszenia, serwerowa — na niej stoją
 * kohorty, „Ugotujmy razem", digest i kolejność). Kolumna jest wyłącznie
 * informacją dla samego kucharza: nie wchodzi do publicznej karty, profilu,
 * powiadomienia ani SEO.
 *
 * TYP `date`, NIE `timestamptz`. Człowiek podaje dzień, nie godzinę; północ
 * UTC jako zastępcza godzina zmieniałaby dzień przy konwersji stref.
 * `NULL` znaczy „nie podano" (domyślnie, i dla wszystkich wykonań sprzed
 * tej migracji — bez backfillu z `cooked_at`, bo to byłoby zmyślone).
 *
 * CHECK `>= 2000-01-01` to jawna dolna granica. Górną granicę („nie z
 * przyszłości według Europe/Warsaw") pilnuje aplikacja, bo CHECK nie może
 * zależeć od `now()` (musi być niezmienny).
 *
 * `ADD COLUMN … NULL` bez DEFAULT zmienia sam katalog (bez przepisania
 * tabeli); CHECK idzie przez `NOT VALID` + `VALIDATE` (AGENTS.md §6).
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedno wykonanie ma zapisany dzień:
 * to świadoma deklaracja człowieka, której kolejny `migrate` nie odtworzy,
 * a „odtworzenie" jej z `cooked_at` byłoby nieprawdą. Ręcznie: zapisz mapę
 * `SELECT id, dzien_gotowania …`, potem wyzeruj kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK = 'cooked_events_dzien_gotowania_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS dzien_gotowania date NULL');

        if (DB::selectOne('SELECT 1 AS jest FROM pg_constraint WHERE conname = ?', [self::CHECK]) === null) {
            DB::statement('ALTER TABLE cooked_events ADD CONSTRAINT '.self::CHECK
                ." CHECK (dzien_gotowania IS NULL OR dzien_gotowania >= DATE '2000-01-01') NOT VALID");
        }

        DB::statement('ALTER TABLE cooked_events VALIDATE CONSTRAINT '.self::CHECK);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Po przerwanym `up()` kolumny może nie być: wtedy nie ma czego liczyć.
        $zapisanych = Schema::hasColumn('cooked_events', 'dzien_gotowania')
            ? DB::table('cooked_events')->whereNotNull('dzien_gotowania')->count()
            : 0;

        if ($zapisanych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań z zapisanym dniem gotowania '
                .'(cooked_events.dzien_gotowania IS NOT NULL): '.$zapisanych.'. '
                .'To dzień, który kucharz świadomie podał; kolejny `migrate` odtworzyłby kolumnę pustą '
                .'i po cichu go zgubił, a odtworzenie z `cooked_at` byłoby nieprawdą (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, dzien_gotowania FROM cooked_events WHERE dzien_gotowania IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE cooked_events DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE cooked_events DROP COLUMN IF EXISTS dzien_gotowania');
    }
};
