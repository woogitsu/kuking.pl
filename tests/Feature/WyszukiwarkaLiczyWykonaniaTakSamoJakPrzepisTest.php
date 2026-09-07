<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Ugotowane N ×" znaczy to samo na karcie w wyszukiwarce i na stronie przepisu.
 *
 * SKĄD SIĘ WZIĄŁ TEN ROZJAZD
 * Audyt przepisów naprawił licznik na stronie przepisu: stało tam gołe
 * `count()` na całej relacji, dziesięć linijek pod galerią, która filtr
 * `widoczneDla()` miała od audytu A4. Licznik zdradzał więc istnienie
 * wykonania osoby, którą widz zablokował — ten sam „oracle istnienia" co
 * zamknięte W7-05.
 *
 * Ta poprawka zostawiła jednak NOWĄ niespójność, i sam audyt ją wskazał:
 * `SearchQuery::recipes()` liczy `withCount('cookedEvents')` bez żadnego
 * filtra. Ta sama liczba, ta sama etykieta „Ugotowane N ×", dwa różne
 * znaczenia w zależności od tego, przez który ekran człowiek na nią patrzy.
 * Czyli dokładnie ta klasa błędu, którą audyt zamykał — tylko przeniesiona
 * o jeden plik dalej.
 *
 * DLACZEGO TEN TEST PORÓWNUJE DWA EKRANY, A NIE PILNUJE LICZBY
 * Asercja „w wyszukiwarce ma być 1" złapałaby dzisiejszy rozjazd i nic
 * poza nim. Ten test wymaga, żeby OBIE liczby były równe — więc obleje się
 * także wtedy, gdy ktoś jutro zmieni regułę na jednym ekranie i zapomni
 * o drugim, w którąkolwiek stronę.
 */
class WyszukiwarkaLiczyWykonaniaTakSamoJakPrzepisTest extends TestCase
{
    use RefreshDatabase;

    public function test_licznik_w_wyszukiwarce_pomija_wykonanie_osoby_zablokowanej(): void
    {
        [$widz, $przepis] = $this->scena();

        $zWyszukiwarki = app(SearchQuery::class)
            ->recipes('sernik', $widz)
            ->firstWhere('id', $przepis->getKey());

        $this->assertNotNull($zWyszukiwarki, 'Przepis nie znalazł się w wynikach — pomiar mierzyłby nie to, co trzeba.');

        // Tyle samo, co pokazuje strona przepisu temu samemu człowiekowi.
        $naStronie = $przepis->cookedEvents()->widoczneDla($widz)->count();

        $this->assertSame(
            $naStronie,
            (int) $zWyszukiwarki->cooked_events_count,
            'Karta w wyszukiwarce i strona przepisu pokazują pod tą samą etykietą '
            .'„Ugotowane N ×" różne liczby. Jedna z nich zdradza istnienie wykonania '
            .'osoby, której widz nie ma prawa zobaczyć.',
        );

        $this->assertSame(1, $naStronie, 'Kontrola: widoczne ma być dokładnie jedno wykonanie z dwóch.');
    }

    public function test_gosc_widzi_wszystkie_wykonania_i_obie_liczby_sie_zgadzaja(): void
    {
        // Asercja KONTROLNA całego pliku. Bez niej test wyżej przechodziłby
        // także wtedy, gdyby licznik zaczął zawsze zwracać zero.
        [, $przepis] = $this->scena();

        $zWyszukiwarki = app(SearchQuery::class)
            ->recipes('sernik', null)
            ->firstWhere('id', $przepis->getKey());

        $this->assertNotNull($zWyszukiwarki);
        $this->assertSame(2, (int) $zWyszukiwarki->cooked_events_count);
        $this->assertSame(2, $przepis->cookedEvents()->widoczneDla(null)->count());
    }

    /**
     * Przepis z dwoma wykonaniami: jednym osoby zwykłej, jednym osoby, którą
     * widz zablokował.
     *
     * @return array{0: User, 1: Recipe}
     */
    private function scena(): array
    {
        $autor = $this->user('autorka');
        $widz = $this->user('widzka');
        $zwykla = $this->user('zwykla');
        $zablokowana = $this->user('zablokowana');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => 'Sernik na zimno',
            'slug' => 'sernik-na-zimno-'.Str::lower(Str::random(6)),
        ]);

        foreach ([$zwykla, $zablokowana] as $kto) {
            CookedEvent::factory()->create([
                'user_id' => $kto->getKey(),
                'recipe_id' => $przepis->getKey(),
                'cooked_at' => now()->subDay(),
            ]);
        }

        app(BlockUser::class)->handle($widz, $zablokowana, '127.0.0.1');

        return [$widz, $przepis->fresh()];
    }
}
