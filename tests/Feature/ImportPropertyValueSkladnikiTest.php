<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Składniki zapisane jako `PropertyValue` w JSON-LD (#2548): nie znikają,
 * a ich dane są zachowane wolnym tekstem — bez przeliczania i zgadywania.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie parsera i importu, nie tekst źródeł.
 */
final class ImportPropertyValueSkladnikiTest extends TestCase
{
    use RefreshDatabase;

    private function html(mixed $skladniki): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Naleśniki',
            'recipeIngredient' => $skladniki,
            'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Usmaż naleśniki na patelni.']],
        ], JSON_UNESCAPED_UNICODE);

        return '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>';
    }

    /** @return list<string> */
    private function odczytane(mixed $skladniki): array
    {
        $przepis = (new ParserJsonLdPrzepisu)->odczytaj($this->html($skladniki));
        $this->assertNotNull($przepis);

        return $przepis->skladniki;
    }

    /** @return array<string, mixed> */
    private function pv(mixed ...$pola): array
    {
        return ['@type' => 'PropertyValue'] + $pola;
    }

    public function test_mieszana_lista_zachowuje_wszystkie_pozycje_w_kolejnosci(): void
    {
        $this->assertSame(
            ['banany', '1 egg', 'szczypta soli'],
            $this->odczytane(['banany', $this->pv(value: 1, name: 'egg'), 'szczypta soli']),
        );
    }

    public function test_wartosc_liczbowa_ulamek_tekstowy_i_jednostka_z_unit_text(): void
    {
        $this->assertSame(
            ['3/4 szklanki mąka', '2.5 kg ziemniaki', '3 jajka'],
            $this->odczytane([
                $this->pv(value: '3/4', unitText: 'szklanki', name: 'mąka'),
                $this->pv(value: 2.5, unitText: 'kg', name: 'ziemniaki'),
                $this->pv(value: 3.0, name: 'jajka'),
            ]),
        );
    }

    public function test_znany_unit_code_daje_jednostke_a_unit_text_ma_pierwszenstwo(): void
    {
        $this->assertSame(
            ['250 g mąka', '1 l mleko', '2 łyżki cukier'],
            $this->odczytane([
                $this->pv(value: 250, unitCode: 'GRM', name: 'mąka'),
                $this->pv(value: 1, unitCode: 'ltr', name: 'mleko'),
                $this->pv(value: 2, unitText: 'łyżki', unitCode: 'GRM', name: 'cukier'),
            ]),
        );
    }

    public function test_nieznany_unit_code_nie_jest_zgadywany_i_prosi_o_sprawdzenie_w_zrodle(): void
    {
        $this->assertSame(
            ['jajko — ilość: 2, kod jednostki ze źródła: C62; sprawdź w źródle'],
            $this->odczytane([$this->pv(value: 2, unitCode: 'C62', name: 'jajko')]),
        );
    }

    public function test_brak_wartosci_zostaje_brakiem_i_nie_dopisuje_jednostki(): void
    {
        $this->assertSame(
            ['sól', 'pieprz'],
            $this->odczytane([
                $this->pv(name: 'sól', unitText: 'szczypta'),
                $this->pv(name: 'pieprz', unitCode: 'C62'),
            ]),
        );
    }

    public function test_nieprawidlowe_obiekty_nie_wytwarzaja_pozycji_a_poprawne_zostaja(): void
    {
        $this->assertSame(
            ['mąka'],
            $this->odczytane([
                $this->pv(),
                $this->pv(value: ['a' => 1], name: ['b']),
                $this->pv(value: true),
                ['@type' => 'Thing', 'name' => 'obcy obiekt', 'value' => 5],
                $this->pv(name: 'mąka'),
            ]),
        );
    }

    public function test_pojedynczy_obiekt_nie_rozpada_sie_na_pola(): void
    {
        $this->assertSame(['1 cebula'], $this->odczytane($this->pv(value: 1, name: 'cebula')));
    }

    public function test_pelny_import_z_adresu_zachowuje_skladniki_bez_modelu_ai(): void
    {
        config(['kuking.import.url.wlaczony' => true]);
        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34'));
        $html = $this->html(['banany', $this->pv(value: '3/4', unitText: 'szklanki', name: 'mąka'), '2 jajka']);
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/nalesniki' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'api.openai.com/*' => Http::response([], 500),
        ]);
        $autor = User::factory()->create();

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/nalesniki'])
            ->assertRedirect();

        $recipe = Recipe::query()->where('author_id', $autor->getKey())->firstOrFail();
        $this->assertSame(
            ['banany', '3/4 szklanki mąka', '2 jajka'],
            $recipe->ingredients()->orderBy('position')->pluck('ingredient_text')->all(),
        );
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }
}
