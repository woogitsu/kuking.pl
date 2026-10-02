<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\ParserMikrodanychPrzepisu;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Sekundy w czasie ISO 8601 przy imporcie (#2546): wchodzą do całego czasu
 * przed sprawdzeniem limitu; ułamek minuty zostaje pustym polem, nie skróceniem.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie parserów i importu, nie tekst źródeł.
 */
final class ImportCzasZSekundamiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, ?int}> */
    public static function czasy(): array
    {
        return [
            'sama liczba sekund w pełnych minutach' => ['PT120S', 2],
            'minuty i sekundy ponad 59' => ['PT1M60S', 2],
            'kontrola: same minuty' => ['PT2M', 2],
            'godziny, minuty i sekundy' => ['PT1H30M30S', null],
            'godzina i 600 sekund' => ['PT1H600S', 70],
            'sekundy dziesiętne dające pełną minutę' => ['PT120.0S', 2],
            'pół minuty to ułamek' => ['PT90S', null],
            'minuta i pół to ułamek' => ['PT1M30S', null],
            'dziesiętne sekundy to ułamek' => ['PT60.5S', null],
            'poniżej minuty' => ['PT30S', null],
            'zero' => ['PT0S', null],
            'dokładnie granica 10080 minut' => ['P7D', 10080],
            'granica w sekundach' => ['PT604800S', 10080],
            'granica z dodatkowymi sekundami nie przechodzi' => ['P7DT60S', null],
            'ponad granicę wyłącznie sekundami' => ['PT604860S', null],
            'błędny zapis' => ['120S', null],
            'ujemny zapis' => ['-PT2M', null],
            'puste' => ['', null],
        ];
    }

    #[DataProvider('czasy')]
    public function test_jedna_zasada_dla_json_ld_i_mikrodanych(string $zapis, ?int $oczekiwane): void
    {
        $this->assertSame($oczekiwane, ParserJsonLdPrzepisu::minuty($zapis));

        foreach (['prepTime', 'cookTime'] as $pole) {
            $json = '{"@type":"Recipe","name":"Danie","'.$pole.'":'.json_encode($zapis)
                .',"recipeIngredient":["mąka"],"recipeInstructions":"Zrób."}';
            $przepis = (new ParserJsonLdPrzepisu)->odczytaj('<script type="application/ld+json">'.$json.'</script>');
            $this->assertNotNull($przepis);
            $this->assertSame($oczekiwane, $pole === 'prepTime' ? $przepis->przygotowanieMinut : $przepis->gotowanieMinut);

            $html = '<div itemscope itemtype="https://schema.org/Recipe"><h1 itemprop="name">Danie</h1>'
                .'<meta itemprop="'.$pole.'" content="'.htmlspecialchars($zapis).'">'
                .'<span itemprop="recipeIngredient">mąka</span><div itemprop="recipeInstructions"><p>Zrób.</p></div></div>';
            $mikro = (new ParserMikrodanychPrzepisu)->odczytaj($html);
            $this->assertNotNull($mikro);
            $this->assertSame($oczekiwane, $pole === 'prepTime' ? $mikro->przygotowanieMinut : $mikro->gotowanieMinut);
        }
    }

    public function test_szkic_z_pt120s_ma_te_same_minuty_co_z_pt2m_a_ulamek_zostawia_puste_pole(): void
    {
        config(['kuking.import.url.wlaczony' => true]);
        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34'));
        $strona = fn (string $prep, string $cook): string => '<html><head><script type="application/ld+json">'
            .json_encode([
                '@type' => 'Recipe', 'name' => 'Placek', 'prepTime' => $prep, 'cookTime' => $cook,
                'recipeIngredient' => ['200 g mąki'], 'recipeInstructions' => 'Upiecz.',
            ]).'</script></head><body></body></html>';
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sekundy' => Http::response($strona('PT120S', 'PT1M60S'), 200, ['Content-Type' => 'text/html']),
            'https://przepisy.example.pl/minuty' => Http::response($strona('PT2M', 'PT2M'), 200, ['Content-Type' => 'text/html']),
            'https://przepisy.example.pl/ulamek' => Http::response($strona('PT90S', 'PT2M'), 200, ['Content-Type' => 'text/html']),
        ]);
        $autor = User::factory()->create();

        $minuty = [];
        foreach (['sekundy', 'minuty', 'ulamek'] as $adres) {
            $this->actingAs($autor)->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/'.$adres])->assertRedirect();
            $recipe = Recipe::query()->where('author_id', $autor->getKey())->latest('created_at')->latest('id')->firstOrFail();
            $wiersz = DB::table('recipes')->where('id', $recipe->getKey())->first();
            $minuty[$adres] = [$wiersz->prep_minutes, $wiersz->cook_minutes];
        }

        $this->assertSame([2, 2], $minuty['minuty'], 'Kontrola dodatnia: zapis w minutach.');
        $this->assertSame($minuty['minuty'], $minuty['sekundy']);
        $this->assertSame([null, 2], $minuty['ulamek'], 'Ułamek minuty zostaje do ręcznego wpisania, bez skracania.');
    }
}
