<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\PominieteWImporcie;
use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\ParserMikrodanychPrzepisu;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Models\ImportPrzepisu;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MapaNazw;
use Tests\TestCase;

final class OstrzezeniaParseraWImporcieTest extends TestCase
{
    use RefreshDatabase;

    public function test_odrzucony_ulamek_minuty_jest_wskazany_przy_polach_json_ld_i_mikrodanych(): void
    {
        $json = '<script type="application/ld+json">'.json_encode([
            '@type' => 'Recipe', 'name' => 'Placek', 'prepTime' => 'PT90S', 'cookTime' => 'PT1M30S',
            'recipeIngredient' => ['mąka'], 'recipeInstructions' => 'Upiecz.',
        ]).'</script>';
        $mikro = '<div itemscope itemtype="https://schema.org/Recipe"><span itemprop="name">Placek</span>'
            .'<meta itemprop="prepTime" content="PT90S"><meta itemprop="cookTime" content="PT1M30S">'
            .'<span itemprop="recipeIngredient">mąka</span><span itemprop="recipeInstructions">Upiecz.</span></div>';

        foreach ([(new ParserJsonLdPrzepisu)->odczytaj($json), (new ParserMikrodanychPrzepisu)->odczytaj($mikro)] as $wynik) {
            $this->assertNotNull($wynik);
            $this->assertNull($wynik->przygotowanieMinut);
            $this->assertNull($wynik->gotowanieMinut);
            $this->assertSame(['przygotowanie' => true, 'gotowanie' => true], $wynik->pominiete->ostrzezeniaParsera, 'IMPORT_2546_CZAS_WIDOCZNY');
        }

        $pelne = (new ParserJsonLdPrzepisu)->odczytaj(str_replace('PT90S', 'PT120S', str_replace('PT1M30S', 'PT2M', $json)));
        $this->assertNotNull($pelne);
        $this->assertNull($pelne->pominiete->doTablicy(), 'Pełne minuty nie tworzą ostrzeżenia.');
    }

    public function test_mieszany_import_pamieta_liczbe_pominietych_skladnikow_i_czas_po_ponownym_otwarciu(): void
    {
        config(['kuking.import.url.wlaczony' => true]);
        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34'));
        $html = '<html><head><script type="application/ld+json">'.json_encode([
            '@type' => 'Recipe', 'name' => 'Placek', 'prepTime' => 'PT90S', 'cookTime' => 'PT2M',
            'recipeIngredient' => ['mąka', ['@type' => 'PropertyValue'], ['@type' => 'Thing', 'value' => 1]],
            'recipeInstructions' => 'Upiecz.',
        ]).'</script></head><body></body></html>';
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/placek' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            'api.openai.com/*' => Http::response([], 500),
        ]);
        $autor = $this->user('autor2546');
        $this->actingAs($autor)->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/placek'])->assertRedirect();

        $szkic = Recipe::query()->where('author_id', $autor->getKey())->firstOrFail();
        $import = ImportPrzepisu::query()->where('recipe_id', $szkic->getKey())->firstOrFail();
        $zapis = PrzepisZImportu::query()->findOrFail($szkic->getKey());
        $this->assertSame(['mąka'], $szkic->ingredients()->pluck('ingredient_text')->all());
        $this->assertNull($szkic->prep_minutes, 'Ułamka nie wolno zaokrąglać.');
        $this->assertSame(2, (int) $szkic->cook_minutes);
        $this->assertSame(['skladniki' => 2, 'przygotowanie' => true], $zapis->pominieteWImporcie()?->ostrzezeniaParsera, 'IMPORT_2548_OSTRZEZENIE_TRWA');
        $this->assertFalse($zapis->pominieteWImporcie()->niepelny());

        $this->actingAs($autor)->get(route('import.show', $import))
            ->assertOk()->assertSee('Nie odczytaliśmy 2 składników ze źródła.')
            ->assertSee('Nie mogliśmy wpisać czasu przygotowania ze źródła.');
        $this->actingAs($autor)->get(route('recipes.edit', $szkic))
            ->assertOk()->assertSee('Nie odczytaliśmy 2 składników ze źródła.')
            ->assertSee('Nie mogliśmy wpisać czasu przygotowania ze źródła.')
            ->assertDontSee('Ten import jest niepełny.');
        $this->actingAs($autor)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertOk()->assertSee('Nie mogliśmy wpisać czasu przygotowania ze źródła.');
    }

    public function test_zamkniety_ksztalt_metadanych_nie_odtwarza_tresci_zrodlowej(): void
    {
        $bezposredni = new PominieteWImporcie(0, 0, [], ['skladniki' => 2, 'przygotowanie' => true, 'tekst' => 'prywatny składnik']);
        $this->assertArrayNotHasKey('tekst', $bezposredni->doTablicy()['ostrzezenia_parsera']);
        $zapis = PominieteWImporcie::zTablicy([
            'skladniki' => 0, 'kroki' => 0, 'obciete' => [],
            'ostrzezenia_parsera' => ['skladniki' => 2, 'przygotowanie' => true, 'tekst' => 'prywatny składnik'],
        ]);
        $this->assertNotNull($zapis);
        $this->assertSame(['skladniki' => 2, 'przygotowanie' => true], $zapis->ostrzezeniaParsera);
        $this->assertArrayNotHasKey('tekst', $zapis->ostrzezeniaParsera);
    }

    public function test_property_value_z_nazwa_i_nieczytelna_iloscia_mowi_co_sprawdzic_bez_liczenia_calego_wiersza_jako_pominiety(): void
    {
        $html = '<script type="application/ld+json">'.json_encode([
            '@type' => 'Recipe', 'name' => 'Placek',
            'recipeIngredient' => [['@type' => 'PropertyValue', 'name' => 'mąka', 'value' => ['x']],
                ['@type' => 'PropertyValue', 'name' => 'sól', 'value' => true]],
            'recipeInstructions' => 'Upiecz.',
        ]).'</script>';
        $wynik = (new ParserJsonLdPrzepisu)->odczytaj($html);
        $this->assertNotNull($wynik);
        $this->assertSame(['mąka — sprawdź ilość w źródle', 'sól — sprawdź ilość w źródle'], $wynik->skladniki);
        $this->assertNull($wynik->pominiete->doTablicy(), 'Wiersz zachowany z ostrzeżeniem nie jest pominięty.');
    }
}
