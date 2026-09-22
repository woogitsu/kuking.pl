<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KompozycjaHeroPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zdjecie_pozostaje_w_drugiej_czesci_hero_z_wariantem_i_opisem(): void
    {
        $author = $this->user('kucharka');
        $media = Media::factory()->create(['owner_id' => $author->id, 'alt_text' => 'Pierogi na talerzu']);
        $recipe = Recipe::factory()->for($author, 'author')->create([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now(),
            'hero_media_id' => $media->id,
        ]);
        $html = (string) $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $image = '//article[contains(@class,"marka-przepis")]/header/div[contains(@class,"marka-przepis-zdjecie")]//img';
        $this->assertSame(1, $xpath->query($image)->length);
        $this->assertSame($media->url('large'), $xpath->evaluate('string('.$image.'/@src)'));
        $this->assertSame('Pierogi na talerzu', $xpath->evaluate('string('.$image.'/@alt)'));
        $this->assertNotSame('', $xpath->evaluate('string('.$image.'/@srcset)'));
        $photo = $image.'/ancestor::div[contains(concat(" ", normalize-space(@class), " "), " photo-zoom ")][1]';
        $link = $photo.'/a[@data-powieksz]';
        $this->assertSame(1, $xpath->query($photo)->length);
        $this->assertSame(1, $xpath->query($link)->length);
        $this->assertSame($media->url('large'), $xpath->evaluate('string('.$link.'/@href)'));
        $this->assertSame('Powiększ zdjęcie', trim($xpath->evaluate('string('.$link.')')));
    }

    public function test_opis_i_prawdziwe_liczby_sa_w_hero_a_akcje_za_nim(): void
    {
        $recipe = Recipe::factory()->for($this->user('autorka'), 'author')->create([
            'title' => 'Pierogi z kapustą i grzybami według rodzinnego przepisu',
            'summary' => 'Własny opis, którego nie wolno zostawić pod panelem akcji.',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
            'prep_minutes' => 20,
            'cook_minutes' => 30,
            'servings' => 4,
            'hero_media_id' => null,
        ]);

        $html = (string) $this->actingAs($this->user('czytelniczka'))
            ->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $article = '//article[contains(concat(" ", normalize-space(@class), " "), " marka-przepis ")]';
        $header = $article.'/header';
        $copy = $header.'/div[contains(concat(" ", normalize-space(@class), " "), " marka-przepis-tekst ")]';

        $this->assertSame(1, $xpath->query($header)->length);
        $this->assertSame($recipe->title, trim($xpath->evaluate('string('.$copy.'/h1)')));
        $description = $xpath->query($copy.'/p[contains(concat(" ", normalize-space(@class), " "), " text-lead ")]');
        $this->assertSame(1, $description->length);
        $this->assertSame($recipe->summary, trim($description->item(0)->textContent));
        $this->assertSame(1, $xpath->query($article.'//p[normalize-space(.)="'.$recipe->summary.'"]')->length);
        $numbers = $xpath->evaluate('string('.$copy.'/ul[contains(@class,"przepis-liczby")])');
        $this->assertStringContainsString('Około 50 min', $numbers);
        $this->assertStringContainsString('4 porcje', $numbers);
        $this->assertSame(0, $xpath->query($header.'//div[contains(@class,"marka-przepis-zdjecie")]')->length);
        $panel = $header.'/following-sibling::*[1][contains(concat(" ", normalize-space(@class), " "), " przepis-panel ")]';
        $this->assertSame(1, $xpath->query($panel)->length);
        $this->assertSame(route('cooked.create', $recipe->slug), $xpath->evaluate('string('.$panel.'//a[normalize-space(.)="Ugotowałem"]/@href)'));
        $save = $panel.'//form[@action="'.route('collections.save', $recipe->slug).'"][button[normalize-space(.)="Zapisuję"]]';
        $this->assertSame(1, $xpath->query($save)->length, 'Szybki zapis pozostaje osobną akcją w panelu.');
        $this->assertSame('POST', $xpath->evaluate('string('.$save.'/@method)'));
        $this->assertSame(1, $xpath->query($save.'/input[@name="_token"]')->length);
    }
}
