<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtr „Bez składnika” w wyszukiwarce przepisów (V2, #2526).
 *
 * Jawna reguła: wypada przepis, w którym KTÓRAŚ linijka składnika (tekst
 * autora) ma wszystkie rdzenie wpisanej nazwy. Zamienniki (`substitutes`)
 * nie liczą się. To nie jest filtr alergenów.
 */
class WyszukiwarkaBezSkladnikaTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka_bez_skladnika');
    }

    /**
     * @param  list<string>  $linie
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(string $tytul, array $linie, array $atrybuty = [], ?string $zamiennik = null): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);

        foreach ($linie as $i => $tekst) {
            $przepis->ingredients()->create([
                'ingredient_text' => $tekst,
                'position' => $i,
                'substitutes' => $zamiennik,
            ]);
        }

        return $przepis;
    }

    /** @return list<string> tytuły w kolejności wyników */
    private function tytuly(string $bez, string $fraza = 'obiad', ?User $widz = null): array
    {
        return app(SearchQuery::class)
            ->recipes($fraza, $widz, 50, null, 0, null, null, [], $bez === '' ? null : $bez)
            ->pluck('title')->all();
    }

    public function test_pomija_przepisy_z_zapisanym_skladnikiem_takze_w_innej_odmianie(): void
    {
        $this->przepis('Obiad z brokułami', ['500 g brokułów', 'makaron']);
        $this->przepis('Obiad z jednym brokułem', ['1 brokuł']);
        $this->przepis('Obiad z kalafiorem', ['kalafior', 'masło']);

        $this->assertSame(['Obiad z kalafiorem'], $this->tytuly('brokuł'));
        $this->assertSame(['Obiad z kalafiorem'], $this->tytuly('brokuły'));
        $this->assertSame(['Obiad z kalafiorem'], $this->tytuly('  BROKUŁ  '));
    }

    public function test_polskie_znaki_nie_maja_znaczenia_jak_w_calej_wyszukiwarce(): void
    {
        $this->przepis('Obiad z żurawiną', ['garść żurawiny']);
        $this->przepis('Obiad z jabłkiem', ['jabłko']);

        $this->assertSame(['Obiad z jabłkiem'], $this->tytuly('zurawina'));
    }

    public function test_nazwa_zlozona_wymaga_wszystkich_slow_w_jednej_linijce(): void
    {
        $this->przepis('Obiad z mąką pszenną', ['mąkę pszenną typ 500']);
        $this->przepis('Obiad z mąką żytnią', ['300 g mąki żytniej', 'pszenny ocet']);

        $this->assertSame(['Obiad z mąką żytnią'], $this->tytuly('mąka pszenna'));
    }

    public function test_alternatywa_w_linijce_liczy_sie_jako_wzmianka(): void
    {
        $this->przepis('Obiad z alternatywą', ['masło lub margaryna']);
        $this->przepis('Obiad z olejem', ['olej']);

        $this->assertSame(['Obiad z olejem'], $this->tytuly('margaryna'));
        $this->assertSame(['Obiad z olejem'], $this->tytuly('masło'));
    }

    public function test_produkt_tylko_w_zamienniku_nie_wyklucza_przepisu(): void
    {
        $this->przepis('Obiad z zamiennikiem', ['kalafior'], [], 'brokuł');

        $this->assertSame(['Obiad z zamiennikiem'], $this->tytuly('brokuł'));
    }

    public function test_przepis_bez_zapisanych_skladnikow_zostaje(): void
    {
        $this->przepis('Obiad bez listy składników', []);
        $this->przepis('Obiad z brokułem', ['brokuł']);

        $this->assertSame(['Obiad bez listy składników'], $this->tytuly('brokuł'));
    }

    public function test_kolejnosc_wynikow_jest_taka_sama_jak_bez_filtra(): void
    {
        $this->przepis('Obiad pierwszy', ['marchew']);
        $this->przepis('Obiad z brokułem', ['brokuł']);
        $this->przepis('Obiad drugi', ['seler']);
        $this->przepis('Obiad trzeci', ['por']);

        $bez = $this->tytuly('');
        $z = $this->tytuly('brokuł');

        $this->assertSame(array_values(array_diff($bez, ['Obiad z brokułem'])), $z);
    }

    public function test_nazwa_bez_rdzeni_nie_wyklucza_wszystkiego(): void
    {
        $this->przepis('Obiad z marchewką', ['marchew']);

        // Bezpośrednie wywołanie domeny z nazwą bez rdzeni (same cyfry): pusta
        // tablica zawiera się w każdej linijce i bez straży wykluczyłaby wszystko.
        $this->assertSame(['Obiad z marchewką'], $this->tytuly('12'));
    }

    public function test_przepisy_niewidoczne_dla_widza_nie_wchodza_do_wynikow(): void
    {
        $this->przepis('Obiad prywatny', ['marchew'], ['visibility' => 'private']);
        $this->przepis('Obiad publiczny', ['marchew']);

        $this->assertSame(['Obiad publiczny'], $this->tytuly('brokuł'));
        $this->assertSame(['Obiad prywatny', 'Obiad publiczny'], collect($this->tytuly('brokuł', 'obiad', $this->autor))->sort()->values()->all());
    }

    public function test_walidacja_nazwy_produktu(): void
    {
        $this->assertSame(['wartosc' => null, 'blad' => null], SearchQuery::skladnikDoPominiecia(null));
        $this->assertSame(['wartosc' => null, 'blad' => null], SearchQuery::skladnikDoPominiecia(['tablica']));
        $this->assertSame(['wartosc' => null, 'blad' => null], SearchQuery::skladnikDoPominiecia('   '));
        $this->assertSame(['wartosc' => 'mąka pszenna', 'blad' => null], SearchQuery::skladnikDoPominiecia("  mąka \n  pszenna "));

        foreach (['12', 'a', '!!!'] as $zla) {
            $wynik = SearchQuery::skladnikDoPominiecia($zla);
            $this->assertNull($wynik['wartosc'], $zla);
            $this->assertStringContainsString('Wpisz nazwę produktu literami', (string) $wynik['blad']);
        }

        $dluga = SearchQuery::skladnikDoPominiecia(str_repeat('ż', SearchQuery::MAX_SKLADNIK_DO_POMINIECIA + 1));
        $this->assertNull($dluga['wartosc']);
        $this->assertStringContainsString('za długa', (string) $dluga['blad']);

        $duzoSlow = SearchQuery::skladnikDoPominiecia('mąka pszenna typ durum tortilla');
        $this->assertNull($duzoSlow['wartosc']);
        $this->assertStringContainsString('krótszą nazwę', (string) $duzoSlow['blad']);
    }

    public function test_ekran_pokazuje_aktywny_filtr_z_opisem_zakresu_i_usuwaniem(): void
    {
        $this->przepis('Obiad z brokułem', ['brokuł']);
        $this->przepis('Obiad z kalafiorem', ['kalafior']);

        $odpowiedz = $this->get(route('search', ['q' => 'obiad', 'bez_skladnika' => 'brokuł']))->assertOk();

        $odpowiedz->assertSee('Obiad z kalafiorem')
            ->assertDontSee('Obiad z brokułem')
            ->assertSee('Pomijamy przepisy, w których autor zapisał w składnikach: „brokuł”.')
            ->assertSee('Sprawdzamy tylko zapisany tekst składników')
            ->assertSee('Usuń filtr „bez: brokuł”')
            ->assertSee('value="brokuł"', false);

        // „Wszystko” z filtrem staje się „Przepisy”, a zakresy przepisów niosą filtr dalej.
        $html = (string) $odpowiedz->getContent();
        $this->assertMatchesRegularExpression('/<a class="chip"\s+href="[^"]*sekcja=tanie[^"]*bez_skladnika=brok[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<a class="chip"\s+href="[^"]*sekcja=przepisy[^"]*czas=30[^"]*bez_skladnika=brok[^"]*"|<a class="chip"\s+href="[^"]*czas=30[^"]*bez_skladnika=brok[^"]*"/', $html);

        // Usunięcie filtra to zwykły odnośnik bez parametru.
        $this->get(route('search', ['q' => 'obiad', 'sekcja' => 'przepisy', 'nawigacja' => 1]))
            ->assertOk()->assertSee('Obiad z brokułem')->assertDontSee('Usuń filtr');
    }

    public function test_pusty_wynik_prowadzi_do_usuniecia_filtra(): void
    {
        $this->przepis('Obiad z brokułem', ['brokuł']);

        $this->get(route('search', ['q' => 'obiad', 'bez_skladnika' => 'brokuł']))
            ->assertOk()
            ->assertSee('Nie ma przepisów do „obiad”, w których nie zapisano „brokuł”.')
            ->assertSee('Usuń ten filtr albo wpisz inną nazwę produktu.')
            ->assertSee('Usuń filtr „bez: brokuł”');
    }

    public function test_zla_nazwa_daje_blad_przy_polu_i_wyniki_bez_filtra(): void
    {
        $this->przepis('Obiad z brokułem', ['brokuł']);

        $this->get(route('search', ['q' => 'obiad', 'bez_skladnika' => '12']))
            ->assertOk()
            ->assertSee('Wpisz nazwę produktu literami, na przykład „brokuł”.')
            ->assertSee('Wyniki poniżej są bez tego filtra.')
            ->assertSee('Obiad z brokułem')
            ->assertSee('value="12"', false)
            ->assertDontSee('Pomijamy przepisy');
    }

    public function test_pokaz_wiecej_niesie_filtr(): void
    {
        foreach (range(1, 25) as $i) {
            $this->przepis('Obiad numer '.$i, ['marchew']);
        }
        $this->przepis('Obiad z brokułem', ['brokuł']);

        $odpowiedz = $this->get(route('search', ['q' => 'obiad', 'bez_skladnika' => 'brokuł']))->assertOk();
        $html = $odpowiedz->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*bez_skladnika=brok[^"]*ile_przepisow=40[^"]*"|href="[^"]*ile_przepisow=40[^"]*bez_skladnika=brok[^"]*"/', (string) $html);
        $this->assertStringNotContainsString('Obiad z brokułem', (string) $html);
    }

    public function test_zakres_ludzie_pomija_filtr_i_nie_pokazuje_pola(): void
    {
        $this->user('obiadowa');

        $this->get(route('search', ['q' => 'obiad', 'sekcja' => 'ludzie', 'bez_skladnika' => 'brokuł']))
            ->assertOk()
            ->assertDontSee('Pomijamy przepisy')
            ->assertDontSee('f-bez-skladnika');
    }

    public function test_formularz_ma_pole_z_widoczna_etykieta_i_bez_natywnej_walidacji(): void
    {
        $html = (string) $this->get(route('search', ['q' => 'obiad']))->assertOk()->getContent();

        $this->assertStringContainsString('<label for="f-bez-skladnika">Pomiń przepisy ze składnikiem</label>', $html);
        $this->assertMatchesRegularExpression('/<form class="panel-formularza" method="GET"[^>]*\snovalidate/', $html);
    }

    public function test_nazwa_produktu_nie_trafia_do_sygnalow_analitycznych(): void
    {
        $this->przepis('Obiad z marchewką', ['marchew']);

        $this->actingAs($this->user('szukajaca'))
            ->get(route('search', ['q' => 'obiad', 'bez_skladnika' => 'brokuł']))
            ->assertOk();

        $sygnaly = ProductSignal::query()->get();
        $this->assertNotEmpty($sygnaly, 'Wyszukiwanie zapisuje sygnał z samą długością frazy.');
        foreach ($sygnaly as $sygnal) {
            $this->assertStringNotContainsString('brok', mb_strtolower(json_encode($sygnal->properties, JSON_UNESCAPED_UNICODE) ?: ''));
        }
    }
}
