<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Przelicz” przy składniku na stronie przepisu (#2533): blok `<details>`
 * z równoważnikami miar, bez JS, tekst autora bez zmian.
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `@unless($ingredient->no_amount)`
 * z `show.blade.php` nie psuje tego testu (bo „sól” nie ma jednostki), ale
 * podmiana `$przeliczony->tekst()` na `$ingredient->ingredient_text`
 * oblewa `test_blok_uwzglednia_wybrane_porcje` („500 g” zamiast „250 g”).
 */
final class PrzeliczMiareNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_blok_jest_przy_miarach_i_nie_ma_go_przy_reszcie(): void
    {
        $przepis = $this->przepis(2, ['25 dag mąki', '2 jajka', 'sól do smaku', '1 szklanka mleka']);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-przelicz-miare'));
        $this->assertStringContainsString('<summary>Przelicz</summary>', $html);
        $this->assertStringContainsString('250 g = 0,25 kg', $html);
        $this->assertStringContainsString('ok. 250 ml', $html);
        // Tekst autora zostaje i nie ma przeliczenia masy na objętość.
        $this->assertStringContainsString('25 dag mąki', $html);
        $this->assertStringNotContainsString('250 g mąki', $html);
    }

    public function test_blok_uwzglednia_wybrane_porcje(): void
    {
        $przepis = $this->przepis(2, ['25 dag mąki']);

        $html = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 4]))->assertOk()->getContent();

        $this->assertStringContainsString('500 g = 0,5 kg', $html);
        $this->assertStringNotContainsString('250 g = 0,25 kg', $html);
    }

    public function test_skladnik_bez_ilosci_nie_ma_bloku(): void
    {
        $przepis = $this->przepis(2, ['2 jajka', 'sól']);
        $przepis->ingredients()->create(['ingredient_text' => '1 łyżka soli', 'no_amount' => true, 'position' => 9]);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-przelicz-miare', $html);
    }

    public function test_kartka_do_druku_nie_ma_bloku_przelicz(): void
    {
        $przepis = $this->przepis(2, ['25 dag mąki']);

        foreach ([['druk' => 1], ['druk' => 1, 'dla' => 'pomocnika']] as $parametry) {
            $html = (string) $this->get(route('recipes.show', [$przepis->slug, ...$parametry]))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-przelicz-miare', $html);
            $this->assertStringNotContainsString('<summary>Przelicz</summary>', $html);
        }
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

        return $przepis;
    }
}
