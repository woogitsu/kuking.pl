<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Actions\ZrobWlasnaWersje;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Porcje\GotoweSztuki;
use App\Domain\Recipes\Porcje\WyborSztuk;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeServingPreference;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jawna liczba gotowych sztuk i przeliczenie „24 pierogi → 36” (#2645, V2).
 *
 * Mierzymy przez PRAWDZIWĄ STRONĘ przepisu, bez JavaScriptu: formularz GET
 * `?sztuki=36` ma dać ten sam współczynnik 1,5 co 6 → 9 porcji, na tym samym
 * skalerze (`PrzeliczSkladnik`). Do tego: jedna podstawa (sztuki wygrywają
 * z porcjami, współczynniki się nie mnożą), brak sztuk = strona jak dotąd,
 * zła liczba nie znika z pola i nie wraca jako surowy adres, a koszt idzie
 * za sztukami, nie za porcjami.
 *
 * KONTROLA UJEMNA (ręcznie): podmiana w `show.blade.php` wyboru skalera
 * (`$wyborSztuk->przelicz`) na `$wyborPorcji->przelicz` oblewa
 * `test_24_do_36_sztuk_daje_ten_sam_wspolczynnik_co_6_do_9_porcji`
 * (200 g zamiast 300 g mąki).
 */
class LiczbaSztukPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Strona przepisu
    // ------------------------------------------------------------------

    #[Test]
    public function test_24_do_36_sztuk_daje_ten_sam_wspolczynnik_co_6_do_9_porcji(): void
    {
        $przepis = $this->przepis();

        $zeSztuk = $this->skladniki($this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk());
        $zPorcji = $this->skladniki($this->get(route('recipes.show', [$przepis->slug, 'porcje' => 9]))->assertOk());

        $this->assertSame(['300 g mąki', '3 jajka', 'szczypta soli', 'sól', '1½ łyżki masła'], $zeSztuk);
        $this->assertSame($zPorcji, $zeSztuk, 'Dla 24 → 36 sztuk współczynnik ma być 1,5, jak dla 6 → 9 porcji.');
    }

    #[Test]
    public function test_strona_mowi_ktora_podstawa_jest_wybrana_i_jak_wrocic(): void
    {
        $przepis = $this->przepis();

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk()
            ->assertSee('Przeliczone na 36 szt. (pierogi).')
            ->assertSee('Autor podał ilości na 24 szt. (pierogi).')
            ->assertDontSee('Na ile porcji?');

        $xpath = $this->xpath($odpowiedz);
        $powrot = $xpath->query('//a[normalize-space(.)="Pokaż ilości z przepisu"]')->item(0);
        $this->assertNotNull($powrot);
        $this->assertSame(route('recipes.show', $przepis->slug).'#skladniki', $this->element($powrot)->getAttribute('href'));

        // Liczba porcji autora nadal jest osobną informacją na stronie.
        $odpowiedz->assertSee('6 porcji');
    }

    #[Test]
    public function test_bez_parametru_strona_pokazuje_tekst_autora_obie_informacje_i_formularz(): void
    {
        $przepis = $this->przepis();

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk()
            ->assertSee('Na ile porcji?')
            ->assertSee('Na ile sztuk?')
            ->assertSee('24 szt. (pierogi)')
            ->assertDontSee('Przeliczone na');

        $this->assertSame(['200 g mąki', '2 jajka', 'szczypta soli', 'sól', '1 łyżka masła'], $this->skladniki($odpowiedz));

        $xpath = $this->xpath($odpowiedz);
        $formularz = $xpath->query('//form[@aria-labelledby="sztuki-wybor-tytul"]')->item(0);
        $this->assertNotNull($formularz);
        $this->assertSame('get', strtolower($this->element($formularz)->getAttribute('method')));
        $this->assertTrue($this->element($formularz)->hasAttribute('novalidate'));
        $this->assertSame(1, $xpath->query('.//label[@for="f-sztuki"]', $formularz)->length, 'Pole ma widoczną etykietę.');
    }

    #[Test]
    public function test_przepis_bez_sztuk_wyglada_jak_dotad_a_parametr_jest_ignorowany(): void
    {
        $przepis = $this->przepis(['yield_count' => null, 'yield_unit' => null]);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk()
            ->assertSee('Na ile porcji?')
            ->assertDontSee('Na ile sztuk?')
            ->assertDontSee('Gotowe sztuki')
            ->assertDontSee('Przeliczone na');

        $this->assertSame(['200 g mąki', '2 jajka', 'szczypta soli', 'sól', '1 łyżka masła'], $this->skladniki($odpowiedz));
    }

    #[Test]
    public function test_przepis_tylko_ze_sztukami_bez_porcji_tez_sie_przelicza(): void
    {
        $przepis = $this->przepis(['servings' => null]);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 12]))->assertOk();

        $this->assertSame(['100 g mąki', '1 jajka', 'szczypta soli', 'sól', '½ łyżki masła'], $this->skladniki($odpowiedz));
    }

    #[Test]
    public function test_sztuki_i_porcje_w_adresie_nie_mnoza_wspolczynnikow(): void
    {
        $przepis = $this->przepis();

        $obie = $this->skladniki($this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36, 'porcje' => 12]))->assertOk());
        $tylkoSztuki = $this->skladniki($this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk());

        $this->assertSame($tylkoSztuki, $obie, 'Liczy się jedna podstawa — sztuki wygrywają z porcjami.');

        // Odnośniki na stronie niosą jedną podstawę (sztuki), nie obie naraz.
        $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36, 'porcje' => 12]))
            ->assertSee('sztuki=36#jak-wydrukowac', false)
            ->assertDontSee('porcje=12', false);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function zleLiczby(): array
    {
        return [
            'litery' => ['abc', ''],
            'zero' => ['0', '0'],
            'ponad limit' => ['10000', '10000'],
            'ulamek' => ['2,5', '2,5'],
            'ujemna' => ['-5', '-5'],
            'znacznik' => ['12"><script>alert(1)</script>', ''],
            'za dlugie' => ['12345678901234', ''],
        ];
    }

    #[Test]
    #[DataProvider('zleLiczby')]
    public function test_zla_liczba_sztuk_zostawia_ilosci_autora_i_mowi_co_zrobic(string $wpis, string $wPolu): void
    {
        $przepis = $this->przepis();

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => $wpis]))->assertOk()
            ->assertSee('Tej liczby sztuk nie da się przeliczyć. Pokazujemy ilości z przepisu.')
            ->assertSee('Wpisz liczbę od 1 do 9999, na przykład 36.')
            ->assertDontSee('Przeliczone na');

        $this->assertSame(['200 g mąki', '2 jajka', 'szczypta soli', 'sól', '1 łyżka masła'], $this->skladniki($odpowiedz));

        // Do pola wraca tylko coś, co wygląda jak liczba — adres nie wraca na stronę surowy.
        $pole = $this->xpath($odpowiedz)->query('//input[@id="f-sztuki"]')->item(0);
        $this->assertNotNull($pole);
        $this->assertSame($wPolu, $this->element($pole)->getAttribute('value'));
        $this->assertStringNotContainsString('<script>alert', (string) $odpowiedz->getContent());
    }

    #[Test]
    public function test_koszt_idzie_za_sztukami_a_wartosci_odzywcze_zostaja_na_porcje(): void
    {
        $przepis = $this->przepis(['estimated_cost_pln' => 24]);

        $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk()
            ->assertSee('Szacunkowy koszt: ok. 36 zł (przeliczone z kosztu podanego przez autora)');

        // Sztuk nie wolno zamienić w porcje: koszt przy porcjach liczy się od porcji.
        $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 12]))->assertOk()
            ->assertSee('Szacunkowy koszt: ok. 48 zł (przeliczone z kosztu podanego przez autora)');
    }

    #[Test]
    public function test_kartka_dla_pomocnika_trzyma_wybor_sztuk(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->get(route('recipes.show', [$przepis->slug, 'druk' => 1, 'dla' => 'pomocnika', 'sztuki' => 36]))
            ->assertOk()
            ->assertSee('Ilość: 36 szt. (pierogi)')
            ->assertSee('(w przepisie autora: 24 szt. (pierogi))')
            ->assertDontSee('Ilość: 6 porcji')
            ->getContent();

        $this->assertStringContainsString('sztuki=36', $html);
    }

    #[Test]
    public function test_link_druku_niesie_wybrane_sztuki(): void
    {
        $przepis = $this->przepis();

        $this->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk()
            ->assertSee('sztuki=36#jak-wydrukowac', false);
    }

    #[Test]
    public function test_jawne_sztuki_i_powrot_do_autora_nie_reaktywuja_zapamietanych_porcji(): void
    {
        $widz = $this->user();
        $przepis = $this->przepis(['servings' => 4]);
        $this->actingAs($widz)->post(route('recipes.porcje.store', $przepis->slug), ['porcje' => '8'])
            ->assertRedirect();

        $zwykly = $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertSame('400 g mąki', $this->skladniki($zwykly)[0]);

        $autorSztuk = $this->actingAs($widz)->get(route('recipes.show', [$przepis->slug, 'sztuki' => 24]))->assertOk();
        $this->assertSame('200 g mąki', $this->skladniki($autorSztuk)[0], 'SZTUKI_2848_JAWNE_24_TO_AUTOR');
        $this->assertStringContainsString('druk=1&amp;sztuki=24#jak-wydrukowac', (string) $autorSztuk->getContent(), 'SZTUKI_2848_DRUK_PODSTAWA');
        $druk24 = $this->element($this->xpath($autorSztuk)->query('//a[@data-drukuj-przepis]')->item(0))->getAttribute('href');
        $this->assertSame('200 g mąki', $this->skladniki($this->actingAs($widz)->get($druk24)->assertOk())[0]);
        $this->assertSame(0, $this->xpath($autorSztuk)->query('//label[normalize-space(.)="Na ile porcji?"]')->length);

        $wieksze = $this->actingAs($widz)->get(route('recipes.show', [$przepis->slug, 'sztuki' => 36]))->assertOk();
        $this->assertSame('300 g mąki', $this->skladniki($wieksze)[0]);
        $powrot = $this->xpath($wieksze)->query('//a[normalize-space(.)="Pokaż ilości z przepisu"]')->item(0);
        $this->assertNotNull($powrot);
        $adresPowrotu = $this->element($powrot)->getAttribute('href');
        $this->assertSame(route('recipes.show', [$przepis->slug, 'porcje' => 'autor']).'#skladniki', $adresPowrotu, 'SZTUKI_2848_POWROT_LINK');
        $poKliknieciu = $this->actingAs($widz)->get($adresPowrotu)->assertOk();
        $this->assertSame('200 g mąki', $this->skladniki($poKliknieciu)[0], 'SZTUKI_2848_POWROT_DO_AUTORA');
        $this->assertStringContainsString('druk=1&amp;porcje=autor#jak-wydrukowac', (string) $poKliknieciu->getContent());
        $drukAutora = $this->element($this->xpath($poKliknieciu)->query('//a[@data-drukuj-przepis]')->item(0))->getAttribute('href');
        $this->assertSame('200 g mąki', $this->skladniki($this->actingAs($widz)->get($drukAutora)->assertOk())[0]);

        $odrzucone = $this->actingAs($widz)->get(route('recipes.show', [$przepis->slug, 'sztuki' => 0]))->assertOk()
            ->assertSee('Pokazujemy ilości z przepisu.');
        $this->assertSame('200 g mąki', $this->skladniki($odrzucone)[0], 'SZTUKI_2848_ZERO_POKAZUJE_AUTORA');
        $this->assertSame('0', $this->element($this->xpath($odrzucone)->query('//input[@id="f-sztuki"]')->item(0))->getAttribute('value'));
        $this->assertStringContainsString('druk=1&amp;porcje=autor#jak-wydrukowac', (string) $odrzucone->getContent());

        $this->assertSame(8.0, (float) RecipeServingPreference::query()
            ->where('user_id', $widz->getKey())->where('recipe_id', $przepis->getKey())->value('servings'));
        $this->assertSame('400 g mąki', $this->skladniki($this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk())[0]);
    }

    // ------------------------------------------------------------------
    // Zapis przez autora
    // ------------------------------------------------------------------

    #[Test]
    public function test_autor_zapisuje_sztuki_osobno_od_porcji(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['servings' => 4]);

        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis->slug), $this->formularz($przepis, ['servings' => '4', 'yield_count' => '24', 'yield_unit' => '  pierogi  ']))
            ->assertSessionHasNoErrors();

        $swiezy = $przepis->fresh();
        $this->assertSame(24, $swiezy->yield_count);
        $this->assertSame('pierogi', $swiezy->yield_unit);
        $this->assertSame(4.0, $swiezy->servings, 'Zapis sztuk nie rusza porcji.');

        $this->get(route('recipes.edit', $swiezy->slug))->assertOk()->assertSee('value="24"', false)->assertSee('value="pierogi"', false);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function bledneSztuki(): array
    {
        return [
            'tekst' => [['yield_count' => 'dwadziescia'], 'pełną liczbą'],
            'zero' => [['yield_count' => '0'], 'większa od zera'],
            'za duzo' => [['yield_count' => '10000'], 'najwyżej 9999'],
            'ulamek' => [['yield_count' => '2.5'], 'pełną liczbą'],
            'opis bez liczby' => [['yield_count' => '', 'yield_unit' => 'pierogi'], 'Wpisz, ile sztuk'],
        ];
    }

    /** @param array<string, string> $dane */
    #[Test]
    #[DataProvider('bledneSztuki')]
    public function test_bledne_sztuki_dostaja_polskie_zdanie_i_nic_sie_nie_zapisuje(array $dane, string $fragment): void
    {
        [$autor, $przepis] = $this->przepisAutora(['yield_count' => 10, 'yield_unit' => 'bułki']);
        $adres = route('recipes.edit', $przepis->slug);

        $this->actingAs($autor)->from($adres)
            ->put(route('recipes.update', $przepis->slug), $this->formularz($przepis, $dane))
            ->assertRedirect($adres)
            ->assertSessionHasErrors(['yield_count']);

        $this->assertStringContainsString($fragment, (string) session('errors')->first('yield_count'));
        $this->assertSame(10, $przepis->fresh()->yield_count);
        $this->assertSame('bułki', $przepis->fresh()->yield_unit);
    }

    #[Test]
    public function test_wyczyszczenie_liczby_czysci_tez_opis_a_brak_pola_nic_nie_rusza(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['yield_count' => 10, 'yield_unit' => 'bułki']);

        // Droga bez tego pola (np. ekran dodawania) nie kasuje po cichu.
        $this->actingAs($autor)->put(route('recipes.update', $przepis->slug), $this->formularz($przepis))
            ->assertSessionHasNoErrors();
        $this->assertSame(10, $przepis->fresh()->yield_count);

        $this->put(route('recipes.update', $przepis->slug), $this->formularz($przepis->fresh(), ['yield_count' => '', 'yield_unit' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($przepis->fresh()->yield_count);
        $this->assertNull($przepis->fresh()->yield_unit);
    }

    #[Test]
    public function test_kreator_zapisuje_sztuki_i_oddaje_je_po_powrocie(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)->test('recipe-wizard')
            ->set('form.title', 'Pierogi ruskie')
            ->set('form.yield_count', '24')
            ->set('form.yield_unit', 'pierogi')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $przepis = Recipe::where('title', 'Pierogi ruskie')->firstOrFail();
        $this->assertSame(24, $przepis->yield_count);
        $this->assertSame('pierogi', $przepis->yield_unit);

        Livewire::actingAs($basia)->test('recipe-wizard', ['recipeId' => $przepis->getKey()])
            ->assertSet('form.yield_count', '24')
            ->assertSet('form.yield_unit', 'pierogi');
    }

    #[Test]
    public function test_kreator_odrzuca_bledna_liczbe_przy_polu_i_zostawia_wpis(): void
    {
        Livewire::actingAs($this->user('basia'))->test('recipe-wizard')
            ->set('form.title', 'Pierogi ruskie')
            ->set('form.yield_count', '0')
            ->set('form.yield_unit', 'pierogi')
            ->call('next')
            ->assertSet('step', 1)
            ->assertHasErrors(['form.yield_count'])
            ->assertSet('form.yield_count', '0')
            ->assertSet('form.yield_unit', 'pierogi')
            ->assertSee('Liczba gotowych sztuk musi być większa od zera.');
    }

    // ------------------------------------------------------------------
    // Wersje, kopia, eksport, baza
    // ------------------------------------------------------------------

    #[Test]
    public function test_sztuki_przezywaja_historie_kopie_i_eksport(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['yield_count' => 24, 'yield_unit' => 'pierogi', 'visibility' => 'public']);

        $wersja = app(SnapshotRecipeVersion::class)->handle($przepis, $autor, 'test');
        $this->assertSame(24, $wersja->snapshot['yield_count']);
        $this->assertSame('pierogi', $wersja->snapshot['yield_unit']);

        $pola = collect((new MigawkaWersji($wersja->snapshot))->pola())->firstWhere('klucz', 'yield_count');
        $this->assertNotNull($pola, 'Porównanie wersji nie zna liczby sztuk.');
        $this->assertSame('24 szt. (pierogi)', $pola['wartosc']);

        $kopia = app(ZrobWlasnaWersje::class)->handle($this->user('kucharz'), $przepis);
        $this->assertSame(24, $kopia->yield_count);
        $this->assertSame('pierogi', $kopia->yield_unit);

        $dane = app(CollectUserExportData::class)->handle($autor->fresh(), new ExportPhotoPlan($autor->fresh()), Carbon::now());
        $wiersz = collect($dane['przepisy'])->firstWhere('adres_w_serwisie', $przepis->slug);
        $this->assertNotNull($wiersz, 'Przepisu nie ma w paczce — test mierzyłby nie to.');
        $this->assertSame(24, $wiersz['gotowe_sztuki']);
        $this->assertSame('pierogi', $wiersz['gotowe_sztuki_co']);
    }

    #[Test]
    public function test_baza_odrzuca_zla_liczbe_i_opis_bez_liczby(): void
    {
        [, $przepis] = $this->przepisAutora();

        foreach ([['yield_count' => 0], ['yield_count' => 10000], ['yield_unit' => 'pierogi'], ['yield_count' => 5, 'yield_unit' => '   ']] as $zle) {
            try {
                DB::transaction(fn () => DB::table('recipes')->where('id', $przepis->id)->update($zle));
                $this->fail('Baza przyjęła niedozwolone '.json_encode($zle));
            } catch (QueryException $e) {
                $this->assertStringContainsString('recipes_yield_', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_czyste_reguly_wyboru_sztuk(): void
    {
        $przepis = new Recipe(['yield_count' => 24, 'yield_unit' => 'pierogi']);

        $this->assertSame(1.5, WyborSztuk::dla($przepis, '36')->mnoznik());
        $this->assertSame(1.0, WyborSztuk::dla($przepis, '24')->mnoznik());
        $this->assertSame('36', WyborSztuk::dla($przepis, 36)->doAdresu());
        $this->assertSame('24', WyborSztuk::dla($przepis, '24')->doAdresu(), 'Jawne sztuki są podstawą także przy mnożniku 1.');
        $this->assertNull(WyborSztuk::dla($przepis, null)->doAdresu());
        $this->assertFalse(WyborSztuk::dla(new Recipe, '36')->dostepny());
        $this->assertSame('24 szt.', GotoweSztuki::etykieta(24));
        $this->assertTrue(WyborSztuk::dla($przepis, ['36'])->odrzucone);
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $atrybuty */
    private function przepis(array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user()->getKey(),
            'servings' => 6,
            'yield_count' => 24,
            'yield_unit' => 'pierogi',
            ...$atrybuty,
        ]);

        foreach ([
            ['200 g mąki', false],
            ['2 jajka', false],
            ['szczypta soli', false],
            ['sól', true],
            ['1 łyżka masła', false],
        ] as $pozycja => [$tekst, $bezIlosci]) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'ingredient_text' => $tekst,
                'no_amount' => $bezIlosci,
                'position' => $pozycja,
            ]);
        }

        return $przepis;
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     * @return array{0: User, 1: Recipe}
     */
    private function przepisAutora(array $atrybuty = []): array
    {
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'title' => 'Pierogi z kapustą', ...$atrybuty]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Ulep pierogi.']);

        return [$autor, $przepis->fresh()];
    }

    /**
     * @param  array<string, mixed>  $dodatkowo
     * @return array<string, mixed>
     */
    private function formularz(Recipe $przepis, array $dodatkowo = []): array
    {
        return [
            'title' => 'Pierogi z kapustą',
            'visibility' => 'public',
            'source_type' => 'own',
            'action' => 'publish',
            'content_revision' => $przepis->content_revision,
            'steps' => [['instruction' => 'Ulep pierogi.']],
            ...$dodatkowo,
        ];
    }

    /** @return list<string> */
    private function skladniki(TestResponse $odpowiedz): array
    {
        $wynik = [];

        foreach ($this->xpath($odpowiedz)->query('//ul[@class="ingredient-list"]/li') as $li) {
            foreach ((new DOMXPath($li->ownerDocument))->query('.//span', $li) as $span) {
                $span->parentNode?->removeChild($span);
            }
            // Blok „Przelicz” (#2533) to podpowiedź na żądanie, nie tekst składnika.
            foreach (iterator_to_array((new DOMXPath($li->ownerDocument))->query('.//details[@data-przelicz-miare]', $li)) as $blok) {
                $blok->parentNode?->removeChild($blok);
            }

            $wynik[] = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
        }

        return $wynik;
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$odpowiedz->getContent());

        return new DOMXPath($dom);
    }

    private function element(?\DOMNode $wezel): \DOMElement
    {
        $this->assertInstanceOf(\DOMElement::class, $wezel);

        return $wezel;
    }
}
