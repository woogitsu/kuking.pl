<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaJsonLd;
use Tests\TestCase;

/**
 * Zdjęcia kroków w `HowToStep.image` — tylko te, które strona pokazuje (#1370).
 *
 * Lista kroków pokazuje zdjęcie przez `<x-photo variant="feed">`, a JSON-LD
 * opisywał krok samym tekstem. Teraz krok ze zdjęciem widocznym na stronie
 * dostaje bezwzględny adres TEGO zdjęcia; krok bez zdjęcia albo ze zdjęciem
 * jeszcze nieprzygotowanym — sam tekst.
 */
class ZdjeciaKrokowWJsonLdPrzepisuTest extends TestCase
{
    use CzytaJsonLd;
    use RefreshDatabase;

    public function test_krok_dostaje_adres_wlasnego_widocznego_zdjecia(): void
    {
        $przepis = Recipe::factory()->zeZdjeciem()->create();
        $autor = $przepis->author_id;
        $gotowe = Media::factory()->create(['owner_id' => $autor]);
        $nieprzygotowane = Media::factory()->pending()->create(['owner_id' => $autor]);
        $kasowane = Media::factory()->create(['owner_id' => $autor, 'status' => Media::STATUS_DELETED]);

        $przepis->steps()->create(['position' => 0, 'instruction' => 'Obierz ziemniaki.', 'media_id' => $gotowe->getKey()]);
        $przepis->steps()->create(['position' => 1, 'instruction' => 'Zagotuj wodę.']);
        $przepis->steps()->create(['position' => 2, 'instruction' => 'Wrzuć kluski.', 'media_id' => $nieprzygotowane->getKey()]);
        $przepis->steps()->create(['position' => 3, 'instruction' => 'Podawaj.', 'media_id' => $kasowane->getKey()]);

        $html = $this->get(route('recipes.show', $przepis))->assertOk()->getContent();
        $recipe = $this->blokiTypu($html, 'Recipe')[0] ?? null;
        $this->assertNotNull($recipe);
        $kroki = $recipe['recipeInstructions'];

        $this->assertCount(4, $kroki);
        $adres = route('media.show', ['media' => $gotowe->getKey(), 'wariant' => 'feed']);
        $this->assertSame(
            ['@type' => 'HowToStep', 'position' => 1, 'text' => 'Obierz ziemniaki.', 'image' => $adres],
            $kroki[0],
        );
        $this->assertStringStartsWith(config('app.url'), $kroki[0]['image']);
        $this->assertNotSame($recipe['image'][0], $kroki[0]['image'], 'Krok nie może dostać zdjęcia głównego.');
        // Ten sam adres widzi człowiek pod krokiem.
        $this->assertStringContainsString('src="'.$adres.'"', $html);
        $this->assertStringNotContainsString($gotowe->object_key, json_encode($recipe, JSON_UNESCAPED_SLASHES));

        foreach ([1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('image', $kroki[$i], 'Krok '.($i + 1).' nie ma widocznego zdjęcia.');
            $this->assertArrayNotHasKey('name', $kroki[$i]);
        }
    }

    public function test_szkic_i_przepis_niepubliczny_nie_emituja_recipe(): void
    {
        $szkic = Recipe::factory()->zeZdjeciem()->create(['status' => Recipe::STATUS_DRAFT, 'published_at' => null]);
        $prywatny = Recipe::factory()->zeZdjeciem()->create(['visibility' => 'private']);

        foreach ([$szkic, $prywatny] as $przepis) {
            $zdjecie = Media::factory()->create(['owner_id' => $przepis->author_id]);
            $przepis->steps()->create(['position' => 0, 'instruction' => 'Krok.', 'media_id' => $zdjecie->getKey()]);

            $html = $this->actingAs($przepis->author)->get(route('recipes.show', $przepis))->assertOk()->getContent();

            $this->assertSame([], $this->blokiTypu($html, 'Recipe'));
            $this->assertStringNotContainsString(
                'media\/'.$zdjecie->getKey(),
                json_encode($this->blokiJsonLd($html)),
            );
        }
    }
}
