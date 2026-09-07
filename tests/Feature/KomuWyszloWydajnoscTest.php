<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sekcja „Komu wyszło" pod przepisem — POMIAR przed poprawką (ten sam kształt
 * błędu co T20/N03, patrz `KomentarzeStronamiTest`, tylko dla wykonań).
 *
 * CO BYŁO ZŁE (zmierzone tym testem 7 IX 2026, na kodzie SPRZED poprawki)
 * `RecipeController::show()` czytało `cookedEvents()->widoczneDla(...)->limit(12)->get()`
 * — `limit()` bez `paginate()`. Przy więcej niż 12 wykonaniach starsze
 * wykonania są NIEOSIĄGALNE z tej strony w ogóle: żadnego przycisku, żadnego
 * adresu, żadnego śladu, że istnieją — mimo że znaczek nad hero
 * ("Ugotowane N ×") uczciwie liczy wszystkie 30. To jest dokładnie sytuacja,
 * przed którą ostrzega zasada projektu: liczba, która nie prowadzi do miejsca,
 * gdzie te wpisy naprawdę są.
 */
class KomuWyszloWydajnoscTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZWykonaniami(int $ile): Recipe
    {
        $autor = $this->user('kucharka');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        for ($i = 0; $i < $ile; $i++) {
            CookedEvent::factory()->create([
                'recipe_id' => $przepis->getKey(),
                'user_id' => $this->user('gosc'.$i)->getKey(),
                'note' => 'Wykonanie numer '.($i + 1),
                'cooked_at' => now()->subMinutes($i),
            ]);
        }

        return $przepis;
    }

    /**
     * KONTROLA. Przy kilku wykonaniach nie ma żadnej paginacji i widać oba —
     * bez tego pomiar niżej mógłby przechodzić dlatego, że strona w ogóle
     * nie pokazuje wykonań.
     */
    public function test_kontrola_dwa_wykonania_widac_oba_bez_paginacji(): void
    {
        $przepis = $this->przepisZWykonaniami(2);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $odpowiedz->assertSee('Wykonanie numer 1', escape: false);
        $odpowiedz->assertSee('Wykonanie numer 2', escape: false);
        $odpowiedz->assertDontSee('Pokaż więcej wykonań', escape: false);
    }

    /** WŁAŚCIWY POMIAR. Trzydzieści wykonań → jedna strona, i droga do reszty. */
    public function test_przepis_z_wieloma_wykonaniami_pokazuje_jedna_strone_i_ma_droge_do_reszty(): void
    {
        $przepis = $this->przepisZWykonaniami(30);

        // Kontrola: baza naprawdę ma 30 wykonań (AGENTS.md §4).
        $this->assertSame(30, $przepis->cookedEvents()->count());

        $zapytaniaCooked = 0;
        $wszystkieZapytania = 0;
        DB::listen(function ($zapytanie) use (&$zapytaniaCooked, &$wszystkieZapytania): void {
            $wszystkieZapytania++;
            if (str_contains($zapytanie->sql, 'from "cooked_events"') && ! str_contains($zapytanie->sql, 'count(*)')) {
                $zapytaniaCooked++;
            }
        });

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();
        $tresc = (string) $odpowiedz->getContent();

        $naStronie = preg_match_all('/Wykonanie numer \d+/', $tresc);

        fwrite(STDERR, sprintf(
            "\n[KomuWyszlo] wykonan w bazie: 30, na stronie: %d, zapytan SELECT do cooked_events: %d, zapytan razem: %d, ma 'Pokaż więcej wykonań': %s\n",
            $naStronie,
            $zapytaniaCooked,
            $wszystkieZapytania,
            $odpowiedz->getContent() && str_contains($tresc, 'Pokaż więcej wykonań') ? 'tak' : 'nie',
        ));

        // Liczba na stronie: stała, nie rośnie z liczbą wykonań w bazie.
        $this->assertLessThanOrEqual(12, $naStronie);

        // DROGA DO RESZTY: bez tego 30-12=18 wykonań jest nieosiągalnych
        // z tego ekranu — to jest sedno błędu, nie tylko liczba zapytań.
        $odpowiedz->assertSee('Pokaż więcej wykonań', escape: false);
    }

    /** Druga strona pokazuje DALSZE wykonania, nie te same. */
    public function test_druga_strona_pokazuje_dalsze_wykonania(): void
    {
        $przepis = $this->przepisZWykonaniami(30);

        $druga = $this->get(route('recipes.show', $przepis->slug).'?wykonania=2')->assertOk();

        $druga->assertDontSee('Wykonanie numer 1<', escape: false);
        $druga->assertSee('Wykonanie numer 13', escape: false);
    }

    /**
     * Nazwa strony paginacji NIE MOŻE kolidować z „komentarze" — obie
     * paginacje żyją na tym samym ekranie (RecipeController::show()).
     */
    public function test_paginacja_wykonan_nie_koliduje_z_paginacja_komentarzy(): void
    {
        $przepis = $this->przepisZWykonaniami(30);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug).'?wykonania=2&komentarze=1')
            ->assertOk();

        // Strona 2 wykonań, strona 1 komentarzy — obie paginacje muszą
        // zaakceptować SWÓJ parametr niezależnie od drugiego.
        $odpowiedz->assertSee('Wykonanie numer 13', escape: false);
    }
}
