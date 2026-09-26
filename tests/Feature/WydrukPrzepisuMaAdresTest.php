<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kartka z wydruku przepisu (#765) niesie adres, pod który da się wrócić.
 * Wygląd samego wydruku mierzy przeglądarka: `node scripts/wydruk-przepisu.mjs`.
 */
class WydrukPrzepisuMaAdresTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_przepisu_niesie_adres_kanoniczny_do_wydruku(): void
    {
        $recipe = Recipe::factory()->create();
        $adres = route('recipes.show', $recipe->slug);

        // Parametr stronicowania komentarzy nie może trafić na kartkę.
        $this->get($adres.'?komentarze=2')
            ->assertOk()
            ->assertSee('<p class="meta m-0 przepis-adres-druk">Adres przepisu: '.e($adres).'</p>', false);
    }

    public function test_adres_do_wydruku_nie_omija_policy(): void
    {
        $recipe = Recipe::factory()->create(['visibility' => 'followers']);

        $odpowiedz = $this->get(route('recipes.show', $recipe->slug));

        $this->assertNotSame(200, $odpowiedz->getStatusCode());
        $odpowiedz->assertDontSee('przepis-adres-druk', false);
    }
}
