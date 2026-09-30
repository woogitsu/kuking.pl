<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Search\SearchQuery;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Filtr „Bez wskazanych alergenów (według autorów)” w wyszukiwarce (#1902, D-333).
 *
 * KONTROLA UJEMNA (ręcznie): (1) zdjęcie `where('recipes.allergen_status', declared)` z `SearchQuery`
 * oblewa `test_filtr_przepuszcza_tylko_zdeklarowane_bez_wybranego_alergenu` (niesprawdzone wpadają);
 * (2) zamiana `NOT (… && …)` na `(… && …)` oblewa ten sam test; (3) usunięcie `+ $alergenyWAdresie`
 * z odnośników oblewa `test_pokaz_wiecej_niesie_wybrane_alergeny`; (4) zdjęcie
 * `config('kuking.alergeny.wlaczone')` z kontrolera oblewa `test_przy_wylaczonej_fladze_filtr_nie_dziala`.
 */
final class AlergenyWyszukiwarkaTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.alergeny.wlaczone' => true]);
        $this->autor = $this->user('autorka_wyszukiwania');
    }

    /** @param  list<string>  $kody */
    private function przepis(string $tytul, string $stan, array $kody = [], array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => $tytul, ...$atrybuty]);

        if ($stan !== 'unchecked') {
            $przepis->forceFill([
                'allergen_status' => $stan,
                'allergens' => $kody,
                'allergens_declared_at' => now(),
            ])->save();
        }

        return $przepis;
    }

    /** @return array<string, Recipe> */
    private function zbior(): array
    {
        return [
            'czysty' => $this->przepis('Zupa jarzynowa czysta', 'declared', []),
            'mleko' => $this->przepis('Zupa jarzynowa z mlekiem', 'declared', ['milk']),
            'niesprawdzony' => $this->przepis('Zupa jarzynowa niesprawdzona', 'unchecked'),
            'przeglad' => $this->przepis('Zupa jarzynowa do przegladu', 'needs_review', ['eggs']),
            'gluten_jaja' => $this->przepis('Zupa jarzynowa z makaronem', 'declared', ['gluten', 'eggs']),
        ];
    }

    /** @param  list<string>  $bez */
    private function szukaj(array $bez, array $dodatkowe = []): TestResponse
    {
        return $this->get(route('search', ['q' => 'zupa jarzynowa', 'bez' => $bez] + $dodatkowe));
    }

    public function test_filtr_przepuszcza_tylko_zdeklarowane_bez_wybranego_alergenu(): void
    {
        $p = $this->zbior();

        $wyniki = app(SearchQuery::class)->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['milk']);

        $this->assertEqualsCanonicalizing(
            [$p['czysty']->getKey(), $p['gluten_jaja']->getKey()],
            $wyniki->pluck('id')->all(),
            'Filtr „bez mleka” ma zostawić tylko zdeklarowane przepisy, w których mleka nie zaznaczono.',
        );
    }

    public function test_przepisy_niesprawdzone_i_do_przegladu_wypadaja_nawet_gdy_maja_pusta_liste(): void
    {
        $p = $this->zbior();

        $wyniki = app(SearchQuery::class)->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['fish']);

        $ids = $wyniki->pluck('id')->all();
        $this->assertNotContains($p['niesprawdzony']->getKey(), $ids, 'Przepis niesprawdzony wpadł do filtra — cisza udaje „nie zawiera”.');
        $this->assertNotContains($p['przeglad']->getKey(), $ids, 'Przepis „do przeglądu” wpadł do filtra.');
        $this->assertCount(3, $ids);
    }

    public function test_kilka_alergenow_naraz_to_logika_zaden_z(): void
    {
        $p = $this->zbior();

        $wyniki = app(SearchQuery::class)->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['milk', 'gluten']);

        $this->assertSame([$p['czysty']->getKey()], $wyniki->pluck('id')->all());
    }

    public function test_bez_filtra_zwraca_wszystko_a_nieznane_kody_sa_ignorowane(): void
    {
        $this->zbior();

        $this->assertCount(5, app(SearchQuery::class)->recipes('zupa jarzynowa'));
        $this->assertCount(5, app(SearchQuery::class)->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['banany', '']));
    }

    public function test_filtr_nie_zmienia_kolejnosci_wynikow(): void
    {
        $this->zbior();
        $szukaj = app(SearchQuery::class);

        $wszystkie = $szukaj->recipes('zupa jarzynowa')->pluck('id')->all();
        $zFiltrem = $szukaj->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['milk'])->pluck('id')->all();

        $oczekiwane = array_values(array_intersect($wszystkie, $zFiltrem));
        $this->assertSame($oczekiwane, $zFiltrem, 'Filtr zmienił kolejność — ma być samym WHERE, bez wpływu na ranking.');
    }

    public function test_filtr_nie_poszerza_widocznosci(): void
    {
        $obca = $this->user('obca_autorka');
        $prywatny = Recipe::factory()->create(['author_id' => $obca->getKey(), 'title' => 'Zupa jarzynowa prywatna', 'visibility' => 'private']);
        $prywatny->forceFill(['allergen_status' => 'declared', 'allergens' => [], 'allergens_declared_at' => now()])->save();
        $szkic = Recipe::factory()->draft()->create(['author_id' => $obca->getKey(), 'title' => 'Zupa jarzynowa szkic']);
        $szkic->forceFill(['allergen_status' => 'declared', 'allergens' => [], 'allergens_declared_at' => now()])->save();

        $ids = app(SearchQuery::class)->recipes('zupa jarzynowa', null, 20, null, 0, null, null, ['milk'])->pluck('id')->all();

        $this->assertSame([], $ids, 'Filtr alergenów wpuścił prywatny przepis albo szkic.');
    }

    public function test_strona_wynikow_pokazuje_komunikaty_przy_aktywnym_filtrze(): void
    {
        $this->zbior();

        $html = (string) $this->szukaj(['milk', 'gluten'])->assertOk()->getContent();

        $this->assertStringContainsString('Pokazujemy tylko przepisy, w których autor zaznaczył brak: gluten, mleko.', $html);
        $this->assertStringContainsString('Przepisy, w których autor nie sprawdził alergenów, są pominięte.', $html);
        $this->assertStringContainsString('To zaznaczenia autorów, nie badania. Przy gotowych produktach zawsze czytaj etykietę.', $html);
        $this->assertStringContainsString('Autor zaznaczył brak: gluten, mleko', $html);
        $this->assertStringContainsString('Zupa jarzynowa czysta', $html);
        $this->assertStringNotContainsString('Zupa jarzynowa z mlekiem', $html);
        $this->assertStringNotContainsString('Zupa jarzynowa niesprawdzona', $html);
        // Zaznaczone pola wracają w formularzu, sekcja jest otwarta.
        $this->assertMatchesRegularExpression('/id="filtr-alergenow"\s+open/', $html);
        $this->assertMatchesRegularExpression('/name="bez\[\]" value="milk"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="bez\[\]" value="gluten"\s+checked/', $html);
    }

    public function test_bez_filtra_karty_nie_maja_linii_o_alergenach_a_formularz_jest_zwiniety(): void
    {
        $this->zbior();

        $html = (string) $this->get(route('search', ['q' => 'zupa jarzynowa']))->assertOk()->getContent();

        $this->assertStringNotContainsString('Autor zaznaczył brak', $html);
        $this->assertStringContainsString('Bez wskazanych alergenów (według autorów)', $html);
        $this->assertDoesNotMatchRegularExpression('/id="filtr-alergenow"\s+open/', $html);
        $this->assertSame(14, preg_match_all('/name="bez\[\]"/', $html));
    }

    public function test_pusty_wynik_mowa_ze_niesprawdzone_sa_pominiete_i_co_zrobic(): void
    {
        $this->przepis('Zupa jarzynowa z mlekiem', 'declared', ['milk']);
        $this->przepis('Zupa jarzynowa niesprawdzona', 'unchecked');

        $html = (string) $this->szukaj(['milk'])->assertOk()->getContent();

        $this->assertStringContainsString('Nie ma przepisów do „zupa jarzynowa”, w których autor zaznaczył brak: mleko.', $html);
        $this->assertStringContainsString('Spróbuj odznaczyć jeden alergen albo poszukaj bez filtra i przeczytaj składniki samodzielnie.', $html);
    }

    public function test_pokaz_wiecej_niesie_wybrane_alergeny(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->przepis('Zupa jarzynowa numer '.chr(97 + $i), 'declared', ['eggs']);
        }

        $html = (string) $this->szukaj(['milk'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="([^"]+)"[^>]*>\s*Pokaż więcej przepisów/', $html);
        preg_match('/href="([^"]+)"[^>]*>\s*Pokaż więcej przepisów/', $html, $m);
        $href = html_entity_decode($m[1]);
        parse_str((string) parse_url($href, PHP_URL_QUERY), $zapytanie);
        $this->assertSame(['milk'], $zapytanie['bez'] ?? null, 'Odnośnik „Pokaż więcej” zgubił filtr alergenów.');
        $this->assertSame('1', $zapytanie['nawigacja'] ?? null);
    }

    public function test_zakresy_przepisow_zachowuja_filtr_a_wszystko_z_filtrem_staje_sie_przepisami(): void
    {
        $this->zbior();

        $html = (string) $this->szukaj(['milk'], ['sekcja' => 'wszystko'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a class="chip"[^>]*href="[^"]*sekcja=przepisy[^"]*bez[^"]*"[^>]*aria-current="page"/', $html);
        $this->assertStringContainsString('Autor zaznaczył brak: mleko', $html);
    }

    public function test_przy_wylaczonej_fladze_filtr_nie_dziala_i_nie_ma_go_na_ekranie(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);
        $this->zbior();

        $html = (string) $this->szukaj(['milk'])->assertOk()->getContent();

        $this->assertStringNotContainsString('name="bez[]"', $html);
        $this->assertStringNotContainsString('Bez wskazanych alergenów', $html);
        $this->assertStringContainsString('Zupa jarzynowa z mlekiem', $html, 'Przy wyłączonej fladze `bez[]` nie może filtrować.');
        $this->assertStringContainsString('Zupa jarzynowa niesprawdzona', $html);
    }

    public function test_nieznany_alergen_w_adresie_jest_pomijany_z_komunikatem_po_polsku(): void
    {
        $this->zbior();

        $html = (string) $this->szukaj(['banany'])->assertOk()->getContent();

        $this->assertStringContainsString('Adres ma alergen, którego nie rozpoznajemy, więc go pomijamy.', $html);
        $this->assertStringContainsString('Zupa jarzynowa niesprawdzona', $html);
    }

    public function test_dziwne_ksztalty_parametru_nie_daja_bledu_500(): void
    {
        $this->zbior();

        foreach ([
            '/szukaj?q=zupa+jarzynowa&bez=milk',
            '/szukaj?q=zupa+jarzynowa&bez[a][b]=milk',
            '/szukaj?q=zupa+jarzynowa&bez[]=',
            '/szukaj?q=zupa+jarzynowa&bez[]=milk&bez[]=milk&bez[]=%00',
            '/szukaj?q=zupa+jarzynowa&'.http_build_query(['bez' => array_fill(0, 200, 'milk')]),
        ] as $adres) {
            $this->get($adres)->assertOk();
        }
    }

    public function test_zakres_ludzie_nie_ma_filtra(): void
    {
        $this->zbior();

        $html = (string) $this->get(route('search', ['q' => 'zupa', 'sekcja' => 'ludzie', 'bez' => ['milk']]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="bez[]"', $html);
    }

    public function test_filtr_niczego_nie_zapisuje_o_szukajacym(): void
    {
        $this->zbior();
        $widz = $this->user('alergik_widz');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa jarzynowa', 'bez' => ['milk', 'gluten']]))->assertOk();

        // Żadnej kolumny o alergiach w `users` i `profiles` — filtr jest bezstanowy (D-299, art. 9 RODO).
        foreach (['users', 'profiles'] as $tabela) {
            foreach (Schema::getColumnListing($tabela) as $kolumna) {
                $this->assertDoesNotMatchRegularExpression('/allerg|alerg/i', $kolumna, "Tabela {$tabela} ma kolumnę {$kolumna}.");
            }
        }

        // Sesja nie niesie wyboru — także w zapamiętanym adresie poprzedniej strony (`_previous.url`).
        $this->assertStringNotContainsString('milk', json_encode(session()->all(), JSON_THROW_ON_ERROR));

        // Sygnał analityczny ma tylko długość frazy i `has_results` — bez filtra.
        $sygnal = ProductSignal::query()->where('signal_name', 'search_performed')->latest('occurred_at')->firstOrFail();
        $this->assertEqualsCanonicalizing(['query_length', 'has_results'], array_keys($sygnal->properties));
        $this->assertStringNotContainsString('milk', json_encode($sygnal->properties, JSON_THROW_ON_ERROR));
    }
}
