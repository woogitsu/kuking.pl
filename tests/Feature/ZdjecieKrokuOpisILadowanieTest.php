<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zdjęcie kroku przepisu: opis dla czytnika ekranu (issue #1304) i sposób
 * ładowania w trybie gotowania (issue #1368).
 *
 * CO SIĘ PSUŁO
 * - #1304: strona przepisu i tryb gotowania dawały zdjęciu kroku bez
 *   `media.alt_text` pusty `alt`, więc czytnik pomijał fotografię, która
 *   jest częścią instrukcji; kopia HTML podpisywała ją samym „Krok N”,
 *   czyli numerem udającym opis obrazu.
 * - #1368: jedyne zdjęcie treści bieżącego kroku dostawało `loading="lazy"`,
 *   więc przeglądarka odkładała jego pobranie do wyliczenia układu, choć
 *   osoba weszła w ten krok właśnie po nie.
 *
 * Testy czytają wyrenderowany HTML, nie źródła — kontrolą dodatnią jest
 * cofnięcie poprawki w widoku, które zapala odpowiedni test.
 */
class ZdjecieKrokuOpisILadowanieTest extends TestCase
{
    use RefreshDatabase;

    public function test_zdjecie_kroku_bez_opisu_ma_kontekst_kroku_na_stronie_przepisu_i_w_trybie_gotowania(): void
    {
        [$przepis] = $this->przepisZeZdjeciemKroku(altText: null);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('alt="Zdjęcie do kroku 2"', false);

        $gotowanie = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->assertSee('alt="Zdjęcie do kroku 2"', false);

        // Powiększenie dostaje ten sam opis co miniatura (#744).
        $gotowanie->assertSee('data-alt="Zdjęcie do kroku 2"', false);
    }

    public function test_wlasny_opis_zdjecia_trafia_bez_zmian_na_trzy_powierzchnie(): void
    {
        [$przepis, $media] = $this->przepisZeZdjeciemKroku(altText: 'Ciasto rozwałkowane na grubość palca');

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('alt="Ciasto rozwałkowane na grubość palca"', false)
            ->assertDontSee('alt="Zdjęcie do kroku 2"', false);

        $this->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->assertSee('alt="Ciasto rozwałkowane na grubość palca"', false);

        $eksport = $this->eksport($przepis, $media);
        $this->assertStringContainsString('alt="Ciasto rozwałkowane na grubość palca"', $eksport);
    }

    public function test_eksport_nie_podaje_samego_numeru_kroku_jako_opisu_zdjecia(): void
    {
        [$przepis, $media] = $this->przepisZeZdjeciemKroku(altText: null);

        $eksport = $this->eksport($przepis, $media);

        $this->assertStringContainsString('alt="Zdjęcie do kroku 2"', $eksport);
        $this->assertStringNotContainsString('alt="Krok 2"', $eksport);
    }

    public function test_zdjecie_biezacego_kroku_nie_jest_leniwe_ani_nie_dostaje_priorytetu(): void
    {
        [$przepis] = $this->przepisZeZdjeciemKroku(altText: null);

        $html = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->getContent();

        $img = $this->znacznikZdjecia($html, 'alt="Zdjęcie do kroku 2"');

        $this->assertStringNotContainsString('loading="lazy"', $img);
        // Brak pomiaru LCP (issue #1368) — więc bez `fetchpriority="high"`.
        $this->assertStringNotContainsString('fetchpriority', $img);
    }

    public function test_zdjecie_kroku_na_stronie_przepisu_zostaje_leniwe(): void
    {
        // Kontrola, że wyjątek dotyczy tylko trybu gotowania: na stronie
        // przepisu zdjęcia kroków leżą pod składnikami, poniżej widoku.
        [$przepis] = $this->przepisZeZdjeciemKroku(altText: null);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $img = $this->znacznikZdjecia($html, 'alt="Zdjęcie do kroku 2"');

        $this->assertStringContainsString('loading="lazy"', $img);
        $this->assertStringNotContainsString('fetchpriority', $img);
    }

    /** @return array{Recipe, Media} */
    private function przepisZeZdjeciemKroku(?string $altText): array
    {
        $autor = User::factory()->create();
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $media = Media::factory()->create(['owner_id' => $autor->getKey(), 'alt_text' => $altText]);

        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Zagnieć ciasto.']);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Rozwałkuj ciasto.', 'media_id' => $media->getKey()]);

        return [$przepis, $media];
    }

    private function eksport(Recipe $przepis, Media $media): string
    {
        $przepis->load('steps.media');

        return view('exports.recipe', [
            'recipe' => $przepis,
            'heroPhoto' => null,
            'scanPhoto' => null,
            'stepPhotos' => $przepis->steps->mapWithKeys(fn (RecipeStep $krok) => [
                $krok->getKey() => $krok->media_id === $media->getKey() ? '../zdjecia/krok.jpg' : null,
            ])->all(),
            'comments' => [],
        ])->render();
    }

    private function znacznikZdjecia(string $html, string $atrybut): string
    {
        $this->assertSame(1, preg_match('/<img\b[^>]*'.preg_quote($atrybut, '/').'[^>]*>/u', $html, $trafienie), 'Brak zdjęcia kroku w HTML.');

        return $trafienie[0];
    }
}
