<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Autorska treść przepisu jest dostępna uczestnikom, ale nie osobie bez dostępu (#2485, #2486). */
final class WspolneGotowanieTrescPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zamienniki_i_zdjecia_sa_przy_wlasciwych_elementach_dla_obu_rol(): void
    {
        $autor = $this->user('autor_wspolnej_tresci');
        $pomocnik = $this->user('pomocnik_wspolnej_tresci');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(), 'position' => 0,
            'ingredient_text' => '200 g masła', 'substitutes' => 'margaryna <nie-wykonuj>',
        ]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(), 'position' => 1,
            'ingredient_text' => '2 jajka', 'substitutes' => null,
        ]);

        $gotowe = Media::factory()->create(['owner_id' => $autor->getKey(), 'alt_text' => 'Zwinięty rulon']);
        $podglad = Media::factory()->zSamymPodgladem()->create(['owner_id' => $autor->getKey(), 'alt_text' => null]);
        $odrzucone = Media::factory()->pending()->create(['owner_id' => $autor->getKey(), 'status' => Media::STATUS_REJECTED]);
        $kroki = [];
        foreach ([$gotowe, $podglad, $odrzucone, null] as $pozycja => $zdjecie) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $recipe->getKey(), 'position' => $pozycja,
                'instruction' => 'Instrukcja kroku '.($pozycja + 1).'.',
                'media_id' => $zdjecie?->getKey(),
            ]);
        }

        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($autor, $recipe);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($autor, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $token);

        foreach ([$autor, $pomocnik] as $osoba) {
            $odpowiedz = $this->actingAs($osoba)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
            $this->assertSame('no-store, private', $odpowiedz->headers->get('Cache-Control'));
            $xpath = $this->xpath($odpowiedz->getContent());

            $maslo = $xpath->query('//details[contains(@class,"cook-ingredients")]//li[contains(.,"200 g masła")]');
            $this->assertCount(1, $maslo);
            $this->assertCount(1, $xpath->query('.//span[contains(@class,"skladnik-zamiennik") and contains(.,"Zamiast tego: margaryna <nie-wykonuj>")]', $maslo->item(0)), 'WSPOLNE_2485_ZAMIENNIK_PRZY_SKLADNIKU');
            $this->assertCount(0, $xpath->query('//details[contains(@class,"cook-ingredients")]//li[contains(.,"2 jajka")]//span[contains(@class,"skladnik-zamiennik")]'));
            $this->assertStringNotContainsString('<nie-wykonuj>', $odpowiedz->getContent());

            $pierwszy = $xpath->query('//li[@id="krok-'.$kroki[0]->getKey().'"]//img[@alt="Zwinięty rulon"]');
            $this->assertCount(1, $pierwszy, 'WSPOLNE_2486_ZDJECIE_PRZY_KROKU');
            $this->assertSame($gotowe->url('feed'), $pierwszy->item(0)->getAttribute('src'));
            $this->assertCount(1, $xpath->query('//li[@id="krok-'.$kroki[1]->getKey().'"]//img[@alt="Zdjęcie do kroku 2" and @loading="lazy"]'));
            $this->assertSame($podglad->url('feed'), $xpath->query('//li[@id="krok-'.$kroki[1]->getKey().'"]//img')->item(0)->getAttribute('src'));
            $this->assertCount(0, $xpath->query('//li[@id="krok-'.$kroki[2]->getKey().'"]//img'));
            $this->assertCount(1, $xpath->query('//li[@id="krok-'.$kroki[2]->getKey().'"]//*[@role="status"]'));
            $this->assertCount(0, $xpath->query('//li[@id="krok-'.$kroki[3]->getKey().'"]//img'));
            $this->assertStringNotContainsString($gotowe->object_key, $odpowiedz->getContent());
        }

        $recipe->forceFill(['visibility' => 'private'])->save();
        $odmowa = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertForbidden()->getContent();
        $this->assertStringNotContainsString('margaryna', $odmowa);
        $this->assertStringNotContainsString($gotowe->url('feed'), $odmowa);
        $this->assertStringNotContainsString('Instrukcja kroku', $odmowa);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($document);
    }
}
