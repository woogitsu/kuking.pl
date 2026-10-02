<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Ta sama ilość ze wspólnego wyboru porcji na stronie i przy gotowaniu (#2629). */
final class PorcjeNieZmieniajaProcentuSkladnikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_i_gotowanie_mnoza_mase_a_nie_procent_i_nie_zmieniaja_tekstu_autora(): void
    {
        $autor = $this->user('autorka2629');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'servings' => 4]);
        $skladnik = RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'ingredient_text' => '30 % śmietanki — 200 g',
            'position' => 0,
        ]);
        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Wymieszaj składniki.',
        ]);

        $strona = $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'porcje' => 8]))->assertOk();
        $gotowanie = $this->get(route('cooking.show', ['recipe' => $przepis->slug, 'porcje' => 8]))->assertOk();

        $this->assertSame('30 % śmietanki — 400 g', $this->tekstSkladnika($strona, '//ul[@class="ingredient-list"]/li'));
        $this->assertSame('30 % śmietanki — 400 g', $this->tekstSkladnika($gotowanie, '//span[@class="cook-skladnik-tresc"]'));
        $zapisany = $skladnik->fresh();
        $this->assertNotNull($zapisany);
        $this->assertSame('30 % śmietanki — 200 g', $zapisany->ingredient_text);
    }

    private function tekstSkladnika(TestResponse $odpowiedz, string $wyrazenie): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$odpowiedz->getContent());
        $elementy = (new DOMXPath($dom))->query($wyrazenie);
        $this->assertNotFalse($elementy);
        $element = $elementy->item(0);

        $this->assertNotNull($element);

        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
