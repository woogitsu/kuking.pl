<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ślad korekty własnego wykonania „Ugotowałem" (issue #2459, decyzja
 * właściciela z 2.10.2026, D-333, wiersz „Paczka C V2 i zasada badania 50+").
 *
 * `cooked_events.poprawiono_at timestamptz NULL` — chwila ostatniej korekty
 * uwagi, opisu zmian albo czasu. `NULL` = wykonanie nigdy nie było poprawiane
 * (tak jest dla wszystkich wierszy sprzed tej migracji; bez backfillu).
 * Tabela nie ma `updated_at` (`CookedEvent::UPDATED_AT = null`), więc sam
 * znacznik „Poprawiono" jest jedynym nowym śladem. Poprzednia treść NIE jest
 * nigdzie przechowywana: wcześniejsza uwaga nie ma bezterminowej kopii.
 *
 * Bez CHECK, bez indeksu, bez DEFAULT: `ADD COLUMN … NULL` zmienia sam katalog
 * (bez przepisania tabeli), a kolumnę czytamy tylko razem z wierszem wykonania.
 *
 * ROLLBACK ODMAWIA (D-088), gdy choć jedno wykonanie ma ślad korekty: znacznik
 * „Poprawiono" jest informacją dla czytających, że tekst różni się od
 * pierwotnego, a kolejny `migrate` odtworzyłby kolumnę pustą i po cichu
 * pokazywał poprawiony tekst jako pierwotny. Ręcznie: zapisz mapę
 * `SELECT id, poprawiono_at …`, wyzeruj kolumnę i ponów rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cooked_events ADD COLUMN IF NOT EXISTS poprawiono_at timestamptz NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Po przerwanym `up()` kolumny może nie być: wtedy nie ma czego liczyć.
        $poprawionych = Schema::hasColumn('cooked_events', 'poprawiono_at')
            ? DB::table('cooked_events')->whereNotNull('poprawiono_at')->count()
            : 0;

        if ($poprawionych > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba wykonań ze śladem korekty '
                .'(cooked_events.poprawiono_at IS NOT NULL): '.$poprawionych.'. '
                .'Ślad mówi czytającym, że tekst został poprawiony po publikacji; kolejny `migrate` odtworzyłby '
                .'kolumnę pustą i poprawiony tekst wyglądałby jak pierwotny (D-088).'."\n\n"
                ."CO ZROBIĆ:\n"
                ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumny nie zna i działa z nią bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                ."      SELECT id, poprawiono_at FROM cooked_events WHERE poprawiono_at IS NOT NULL;\n"
                .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
            );
        }

        DB::statement('ALTER TABLE cooked_events DROP COLUMN IF EXISTS poprawiono_at');
    }
};
