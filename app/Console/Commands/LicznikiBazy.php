<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Punkt odniesienia do ćwiczenia odtworzenia bazy (issue #2223).
 *
 * `docs/infra/KOPIE_I_ODTWORZENIE.md` §4A każe w kroku 1 odczytać liczniki
 * z produkcji, a w kroku 5 porównać je z tym, co wyszło z odtworzonej kopii
 * (`SELECT count(*) FROM …` w psql). Do 29.09.2026 krok 1 szedł przez
 * `php artisan tinker`, którego w obrazie produkcyjnym już nie ma (D-333).
 *
 * LICZY WIERSZE TABEL, NIE MODELE. `psql` w kroku 5 liczy wszystkie wiersze,
 * a `Post::count()` z dawnej wersji kroku 1 pomija wpisy miękko usunięte
 * (`Post` i `Recipe` mają `SoftDeletes`) — porównanie rozjeżdżało się więc
 * przy pierwszym usuniętym wpisie, bez żadnej utraty danych.
 * Stąd `DB::table()` i te same cztery tabele, w tej samej kolejności, co
 * zapytania w kroku 5.
 *
 * Tylko `SELECT count(*)`; niczego nie zapisuje, nie wypisuje żadnych treści
 * ani identyfikatorów.
 */
class LicznikiBazy extends Command
{
    /** Te same tabele i ta sama kolejność co w §4A krok 5. */
    public const TABELE = ['users', 'posts', 'recipes', 'cooked_events'];

    protected $signature = 'kuking:liczniki-bazy';

    protected $description = 'Liczy wiersze users, posts, recipes i cooked_events — punkt odniesienia do ćwiczenia odtworzenia kopii (tylko odczyt)';

    public function handle(): int
    {
        foreach (self::TABELE as $tabela) {
            $this->line($tabela.' '.DB::table($tabela)->count());
        }

        return self::SUCCESS;
    }
}
