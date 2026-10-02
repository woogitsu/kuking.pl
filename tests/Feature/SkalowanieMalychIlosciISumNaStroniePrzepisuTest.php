<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Porcje\PrzeliczonySkladnik;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zaokrąglanie małych ilości w g/ml (#2655) i suma ilości bez przeliczenia
 * po kawałku (#2609) — przez prawdziwą stronę przepisu i tryb gotowania.
 * Reguły czystego przelicznika: `Tests\Unit\PrzeliczSkladnikTest`.
 *
 * KONTROLA UJEMNA (ręcznie):
 *  - #2655: przywrócenie w `IloscKuchenna::zaokraglij()` kroku 0,05 dla
 *    kg/l oblewa `test_ta_sama_masa_w_roznych_jednostkach_na_stronie`
 *    („0,05 kg drożdży” zamiast „0,025 kg drożdży”);
 *  - #2609: `toSuma()` zwracające `false` oblewa
 *    `test_suma_masy_zostaje_bez_przeliczenia_z_uwaga` („2 kg i 200 g mąki”).
 */
final class SkalowanieMalychIlosciISumNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_ta_sama_masa_w_roznych_jednostkach_na_stronie(): void
    {
        $przepis = $this->przepis(4, ['0,1 kg drożdży', '100 g drożdży', '0,1 l mleka', '100 ml mleka']);

        $html = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 1]))->assertOk()->getContent();

        foreach ([
            '<strong class="skladnik-przeliczony">0,025 kg</strong> drożdży',
            '<strong class="skladnik-przeliczony">25 g</strong> drożdży',
            '<strong class="skladnik-przeliczony">0,025 l</strong> mleka',
            '<strong class="skladnik-przeliczony">25 ml</strong> mleka',
        ] as $oczekiwany) {
            $this->assertStringContainsString($oczekiwany, $html, 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        }

        $this->assertStringNotContainsString('0,05 kg', $html, 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        $this->assertStringNotContainsString('0,05 l', $html, 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
    }

    public function test_suma_masy_zostaje_bez_przeliczenia_z_uwaga(): void
    {
        $przepis = $this->przepis(2, ['1 kg i 200 g mąki', '500 g (2 × 250 g) cukru', '2 jajka']);

        $html = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 4]))->assertOk()->getContent();

        $this->assertStringContainsString('1 kg i 200 g mąki', $html, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertStringContainsString('500 g (2 × 250 g) cukru', $html, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertStringNotContainsString('2 kg i 200 g', $html, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertSame(2, substr_count($html, 'data-skladnik-suma'), 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertStringContainsString(e(PrzeliczonySkladnik::UWAGA_SUMA), $html);
        // Zwykły wiersz w tym samym przepisie przelicza się dalej.
        $this->assertStringContainsString('<strong class="skladnik-przeliczony">4</strong> jajka', $html);
        // Blok „Przelicz” (#2533) nie podaje równoważników samego pierwszego członu sumy.
        $this->assertStringNotContainsString('data-przelicz-miare', $html);
    }

    public function test_suma_bez_zmiany_porcji_nie_ma_uwagi(): void
    {
        $przepis = $this->przepis(2, ['1 kg i 200 g mąki']);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString('1 kg i 200 g mąki', $html);
        $this->assertStringNotContainsString('data-skladnik-suma', $html);
    }

    public function test_blok_przelicz_z_2533_dziala_po_zmianie_porcji(): void
    {
        $przepis = $this->przepis(2, ['25 dag mąki', '0,1 kg drożdży']);

        $html = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 4]))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-przelicz-miare'));
        $this->assertStringContainsString('500 g = 0,5 kg', $html);
        // 0,1 kg × 2 = 0,2 kg = 200 g = 20 dag.
        $this->assertStringContainsString('200 g = 20 dag', $html);
    }

    public function test_tryb_gotowania_ma_te_same_reguly(): void
    {
        $przepis = $this->przepis(4, ['0,1 kg drożdży', '100 g drożdży', '1 kg i 200 g mąki']);

        $html = $this->get(route('cooking.show', [$przepis->slug, 'porcje' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('<strong class="skladnik-przeliczony">0,025 kg</strong> drożdży', $html, 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        $this->assertStringContainsString('<strong class="skladnik-przeliczony">25 g</strong> drożdży', $html, 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        $this->assertStringContainsString('1 kg i 200 g mąki', $html, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertSame(1, substr_count($html, 'data-skladnik-suma'), 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertStringContainsString(e(PrzeliczonySkladnik::UWAGA_SUMA), $html);
    }

    /** @param list<string> $skladniki */
    private function przepis(int $porcje, array $skladniki): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => User::factory()->create()->getKey(),
            'servings' => $porcje,
        ]);

        foreach ($skladniki as $pozycja => $tekst) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'ingredient_text' => $tekst,
                'no_amount' => false,
                'position' => $pozycja,
            ]);
        }

        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Wymieszaj.']);

        return $przepis;
    }
}
