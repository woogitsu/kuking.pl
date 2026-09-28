<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** #2059: strażnik podpisu i usunięcie kolumn są jedną granicą dla zapisów. */
#[Group('dwa-polaczenia')]
final class RollbackWersjiTrzymaBlokadeTest extends TestDwochPolaczen
{
    public function test_rollback_bierze_blokade_zapisow_przed_sprawdzeniem_wersji(): void
    {
        $this->assertSame(0, (int) DB::table('recipes')->whereNotNull('forked_at')->count());

        // SHARE zatrzymuje tylko próbę przejęcia tabeli. Obserwujemy
        // rzeczywisty SQL wykonywany przez down(), bez kopiowania go do testu.
        $bariera = $this->nowePolaczenie();
        $bariera->beginTransaction();
        $bariera->exec('LOCK TABLE recipes IN SHARE MODE');
        $rollback = $this->wTle('cofnij-podpis-wersji-2059', []);
        $wynik = null;

        try {
            $this->czekajNaZablokowane(1);
            $zapytania = $this->obserwator->query(
                "SELECT query FROM pg_stat_activity WHERE datname = current_database()
                 AND wait_event_type = 'Lock' AND query LIKE 'LOCK TABLE recipes IN ACCESS EXCLUSIVE MODE%'",
            )->fetchAll(PDO::FETCH_COLUMN);
            $this->assertCount(1, $zapytania, 'Rollback musi zacząć od blokady tabeli, zanim policzy wersje.');

            $this->zwolnijBariere($bariera);
            $wynik = $rollback->wynik();
            $this->assertTrue($wynik['ok'], $wynik['komunikat']);
            $this->assertFalse(Schema::hasColumn('recipes', 'forked_at'));
            $this->assertFalse(Schema::hasColumn('recipes', 'forked_from_id'));
        } finally {
            if ($bariera->inTransaction()) {
                $this->zwolnijBariere($bariera);
            }
            if ($wynik === null) {
                $rollback->wynik();
            }
            if (! Schema::hasColumn('recipes', 'forked_at')) {
                $migracja = require base_path('database/migrations/2026_09_26_100000_add_forked_from_to_recipes.php');
                $migracja->up();
            }
        }

        $this->assertTrue(Schema::hasColumn('recipes', 'forked_at'), 'Przyrząd musi odtworzyć schemat dla dalszych testów.');
    }
}
