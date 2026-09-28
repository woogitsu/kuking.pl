<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Link miniatury w wierszowej karcie przepisu musi mieć własną nazwę (#2063). */
class LinkMiniaturyPrzepisuMaNazweTest extends TestCase
{
    use RefreshDatabase;

    public function test_pusta_nazwa_zdjecia_nie_pozostawia_linku_miniatury_bez_nazwy(): void
    {
        $media = Media::factory()->create(['alt_text' => null]);
        $recipe = Recipe::factory()->create([
            'title' => 'Zupa <babci> & kluski',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'wiersz');
        $link = '//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]';

        $this->assertSame(1, $xpath->query($link)->length);
        $this->assertSame(route('recipes.show', $recipe->slug), $xpath->evaluate('string('.$link.'/@href)'));
        $this->assertSame('Zobacz przepis: '.$recipe->title, $xpath->evaluate('string('.$link.'/@aria-label)'));
        $this->assertSame('', $xpath->evaluate('string('.$link.'//img/@alt)'));
        $this->assertSame($recipe->title, trim($xpath->evaluate('string(//article//h3/a)')));
    }

    public function test_link_miniatury_nazywa_cel_takze_przy_autorskim_opisie_zdjecia(): void
    {
        $media = Media::factory()->create(['alt_text' => 'Miseczka zupy']);
        $recipe = Recipe::factory()->create([
            'title' => 'Zupa jarzynowa',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'wiersz');
        $link = '//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]';

        $this->assertSame('Zobacz przepis: Zupa jarzynowa', $xpath->evaluate('string('.$link.'/@aria-label)'));
        $this->assertSame('Miseczka zupy', $xpath->evaluate('string('.$link.'//img/@alt)'));
    }

    public function test_kafel_zachowuje_jeden_nazwany_link_bez_linku_miniatury(): void
    {
        $media = Media::factory()->create(['alt_text' => null]);
        $recipe = Recipe::factory()->create([
            'title' => 'Placki ziemniaczane',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'kafel');

        $this->assertSame(0, $xpath->query('//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]')->length);
        $this->assertSame('Zobacz przepis: Placki ziemniaczane', $xpath->evaluate('string(//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-otworz ")]/@aria-label)'));
    }

    private function karta(Recipe $recipe, string $uklad): DOMXPath
    {
        $html = Blade::render('<x-recipe-card :recipe="$recipe" :uklad="$uklad" />', [
            'recipe' => $recipe->fresh(),
            'uklad' => $uklad,
        ]);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}
