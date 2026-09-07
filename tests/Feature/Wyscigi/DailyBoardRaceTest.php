<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KANDYDAT (audyt zewnętrzny, grupa 2/4): `DailyBoardController::update()`
 * kasuje dzisiejszy wybór i tworzy go od nowa — BEZ transakcji obejmującej
 * całość i BEZ przechwycenia zderzenia z UNIQUE (`daily_picks` ma
 * `unique(['shown_on', 'subject_type', 'subject_id'])`, migracja
 * `create_daily_picks_table`).
 *
 * SCENARIUSZ: gospodarz klika "Zapisz" dwa razy pod rząd (strona wolno się
 * ładuje — dokładnie ten przypadek, o którym mówi AGENTS.md dla grupy 50+),
 * z tym samym wyborem w obu żądaniach. Dwa żądania mogą przeplatać się tak:
 *
 *   żądanie A: DELETE dzisiejszych (pusto, no-op)
 *   żądanie B: DELETE dzisiejszych (wciąż pusto, no-op)
 *   żądanie A: INSERT wybranej osoby
 *   żądanie B: INSERT TEJ SAMEJ osoby → zderzenie z UNIQUE
 *
 * PRZED NAPRAWĄ `DailyPick::create()` (zwykłe Eloquentowe `create()`, nie
 * `firstOrCreate()`) NIE łapało `UniqueConstraintViolationException` — więc
 * żądanie B kończyło się nieprzechwyconym `QueryException`, czyli 500 dla
 * człowieka, który tylko kliknął "Zapisz" drugi raz. Zmierzone tym testem
 * (przed poprawką `DailyBoardController::update()`/`utworzPozycje()`
 * rzucał `Illuminate\Database\QueryException` dokładnie w tym scenariuszu).
 *
 * METODA POMIARU: podkładamy konkurencyjny wiersz DOKŁADNIE między DELETE-em
 * a pierwszym INSERT-em jednego wywołania `update()` — przez `DB::listen()`
 * na instrukcji DELETE. To odtwarza dokładnie przeplot "żądanie B zdążyło
 * między moim DELETE a moim INSERT-em".
 *
 * PO NAPRAWIE: całość idzie w jednej transakcji, a każdy `INSERT` — we
 * własnej zagnieżdżonej transakcji (SAVEPOINT) z przechwyceniem zderzenia,
 * ten sam wzorzec co `SaveRecipeToCollection`/`SavePostToCollection`
 * (issue #43). Test sprawdza, że wynik podwójnego zapisu to JEDNA pozycja
 * na tablicy, bez błędu.
 */
class DailyBoardRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyscig_przy_podwojnym_zapisie_tablicy_nie_konczy_sie_bledem_bazy(): void
    {
        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos');

        $konkurencyjnyWstawiony = false;

        $listener = function ($query) use (&$konkurencyjnyWstawiony, $ktos, $gospodarz): void {
            if ($konkurencyjnyWstawiony) {
                return;
            }

            $sql = strtolower($query->sql);

            if (! str_starts_with(trim($sql), 'delete') || ! str_contains($sql, '"daily_picks"')) {
                return;
            }

            $konkurencyjnyWstawiony = true;

            // "Drugie żądanie" wygrało wyścig: zdążyło wstawić DOKŁADNIE
            // tę samą pozycję (ta sama osoba, ten sam dzień), zanim to
            // żądanie zdążyło wykonać swój INSERT.
            DB::table('daily_picks')->insert([
                'id' => (string) Str::uuid(),
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $ktos->getKey(),
                'position' => 0,
                'curator_id' => $gospodarz->getKey(),
                'created_at' => now(),
            ]);
        };

        DB::listen($listener);
        $this->withoutExceptionHandling();

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), [
            'osoby' => [$ktos->getKey()],
        ])->assertRedirect();

        $this->assertTrue($konkurencyjnyWstawiony, 'Test nie odtworzył zamierzonego przeplotu — konkurencyjny wiersz nigdy nie został wstawiony.');

        // Jedna pozycja, nie dwie: konkurencyjny wiersz i próba tego
        // żądania opisują TĘ SAMĄ osobę na TEN SAM dzień — UNIQUE i tak by
        // drugiej nie przepuścił, ale teraz kod sam się na to godzi zamiast
        // wywalać wyjątkiem.
        $this->assertSame(1, DailyPick::query()->where('subject_id', $ktos->getKey())->count());

        // Wpis do audytu ma powstać mimo złapanego zderzenia — to nie jest
        // porażka zapisu z punktu widzenia gospodarza, tylko jedno zapisanie
        // widziane dwa razy.
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'daily_board.updated')->count());
    }
}
