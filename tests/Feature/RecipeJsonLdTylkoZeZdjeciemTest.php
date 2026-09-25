<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `Recipe` w JSON-LD tylko z gotowym zdjęciem (#1005).
 *
 * Google wymaga `image` w `Recipe`. Bez zdjęcia — albo ze zdjęciem jeszcze
 * w przetwarzaniu — `array_filter` wyrzucał klucz, a niepełny obiekt i tak
 * trafiał do HTML-u. `BreadcrumbList` zdjęcia nie potrzebuje i zostaje.
 */
class RecipeJsonLdTylkoZeZdjeciemTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_bez_zdjecia_nie_deklaruje_recipe(): void
    {
        $przepis = Recipe::factory()->create();

        $typy = array_column($this->blokiJsonLd($przepis), '@type');

        $this->assertNotContains('Recipe', $typy);
        $this->assertContains('BreadcrumbList', $typy);
    }

    public function test_przepis_ze_zdjeciem_w_przetwarzaniu_nie_deklaruje_recipe(): void
    {
        foreach ([Media::STATUS_PENDING, Media::STATUS_PROCESSING] as $status) {
            $autor = User::factory()->create();
            $zdjecie = Media::factory()->zSamymPodgladem()->create(['owner_id' => $autor->getKey(), 'status' => $status]);
            $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'hero_media_id' => $zdjecie->getKey()]);

            $bloki = $this->blokiJsonLd($przepis);
            $typy = array_column($bloki, '@type');

            $this->assertNotContains('Recipe', $typy, "Status {$status} wystawił niepełny `Recipe`.");
            $this->assertContains('BreadcrumbList', $typy);
            $this->assertStringNotContainsString($zdjecie->object_key, json_encode($bloki, JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_przepis_z_gotowym_zdjeciem_ma_publicznie_dostepny_obraz(): void
    {
        $przepis = Recipe::factory()->zeZdjeciem()->create();
        $zdjecie = $przepis->heroMedia;
        $wariant = $zdjecie->wariantDoSerwowania('large');
        $this->assertNotNull($wariant);
        Storage::fake($zdjecie->variantsDisk());
        Storage::disk($zdjecie->variantsDisk())->put($wariant['klucz'], 'webp');

        $recipe = collect($this->blokiJsonLd($przepis))->firstWhere('@type', 'Recipe');

        $this->assertNotNull($recipe, 'Przepis z gotowym zdjęciem musi wystawić `Recipe`.');
        $this->assertIsArray($recipe['image'] ?? null);
        $this->assertCount(1, $recipe['image']);
        $adres = $recipe['image'][0];
        $this->assertStringStartsWith(config('app.url'), $adres, 'Adres obrazu musi być bezwzględny.');
        $this->assertSame(route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => 'large']), $adres);

        // Gość musi dostać bajty: 200 z dysku lokalnego albo 302 na podpisany
        // adres pliku (tak działa R2). 403/404 znaczyłoby, że Google dostał
        // obraz, którego nie może pobrać.
        $this->assertGuest();
        $odpowiedz = $this->get($adres);
        $this->assertContains($odpowiedz->getStatusCode(), [200, 302]);
        if ($odpowiedz->getStatusCode() === 302) {
            $this->assertNotEmpty($odpowiedz->headers->get('Location'));
        }
    }

    /** @return list<array<string, mixed>> */
    private function blokiJsonLd(Recipe $przepis): array
    {
        $html = $this->get(route('recipes.show', $przepis))->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $bloki);

        return array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $bloki[1],
        );
    }
}
