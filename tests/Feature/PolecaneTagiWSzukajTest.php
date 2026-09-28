<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolecaneTagiWSzukajTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_polecane_tagi_maja_prawdziwa_kolejnosc_opisy_i_dzialajace_adresy(): void
    {
        $second = Tag::factory()->create(['name' => 'Długi tag rodzinnego gotowania', 'slug' => 'rodzinne']);
        $first = Tag::factory()->create(['name' => 'Chleb <domowy>', 'slug' => 'chleb-domowy']);
        TagPromotion::create(['tag_id' => $second->id, 'position' => 20, 'note' => null]);
        TagPromotion::create(['tag_id' => $first->id, 'position' => 2, 'note' => 'Zdanie gospodarza <script>alert(1)</script>']);
        $hidden = Tag::factory()->create();
        $hidden->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $merged = Tag::factory()->create();
        $merged->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $first->id])->save();
        foreach ([$hidden, $merged] as $tag) {
            TagPromotion::create(['tag_id' => $tag->id, 'position' => 1]);
        }
        Tag::factory()->create(['name' => 'Niepromowany']);
        foreach ([null, $this->user('szukajacy')] as $viewer) {
            if ($viewer) {
                $this->actingAs($viewer);
            }
            foreach (['wszystko', 'ludzie', 'przepisy', 'szybkie'] as $section) {
                $html = (string) $this->get(route('search', ['sekcja' => $section]))->assertOk()->getContent();
                $xpath = $this->xpath($html);
                $scope = '//section[@aria-labelledby="polecane-tagi-title"]';
                $this->assertSame(1, $xpath->query($scope.'//a[@href="'.route('tags.index').'" and normalize-space(.)="Wszystkie tagi"]')->length);
                $links = $xpath->query($scope.'//li/a');
                $this->assertSame(2, $links->length);
                $this->assertSame(route('tags.show', $first), self::elementDom($links->item(0))->getAttribute('href'));
                $this->assertSame(route('tags.show', $second), self::elementDom($links->item(1))->getAttribute('href'));
                $this->assertSame($first->name, $xpath->query($scope.'//li/h3')->item(0)->textContent);
                $this->assertSame($second->name, $xpath->query($scope.'//li/h3')->item(1)->textContent);
                $this->assertSame('Zobacz tag: '.$first->name, self::elementDom($links->item(0))->getAttribute('aria-label'));
                $this->assertSame('Zdanie gospodarza <script>alert(1)</script>', $xpath->query($scope.'//li/p')->item(0)->textContent);
                $this->assertSame(1, $xpath->query($scope.'//li/p')->length);
                $this->assertSame(0, $xpath->query($scope.'//script')->length);
                $this->assertSame(1, $xpath->query('//main//form[@method="GET"]//input[@name="sekcja" and @value="'.$section.'"]')->length);
                $this->assertSame(1, $xpath->query('//main//form[@method="GET"]//input[@name="q"]')->length);
            }
            $this->get(route('tags.show', $first))->assertOk();
            $this->get(route('tags.show', $second))->assertOk();
            $this->get(route('tags.show', $hidden))->assertNotFound();
            $this->get(route('tags.index'))->assertOk();
        }
    }

    public function test_pusty_stan_nie_wymysla_tagow_i_nie_zastepuje_wynikow(): void
    {
        $response = $this->get(route('search'))->assertOk();
        $response->assertSee('Nie ma jeszcze polecanych tagów.')->assertSee(route('discover'), false);
        $xpath = $this->xpath((string) $response->getContent());
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="polecane-tagi-title"]//a[@href="'.route('tags.index').'" and normalize-space(.)="Wszystkie tagi"]')->length);
        $this->get(route('tags.index'))->assertOk();
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="polecane-tagi-title"]//li')->length);
        foreach (['a', 'nieistniejacyprzepis'] as $phrase) {
            $html = (string) $this->get(route('search', ['q' => $phrase]))->assertOk()->getContent();
            $this->assertSame(0, $this->xpath($html)->query('//section[@aria-labelledby="polecane-tagi-title"]')->length);
        }
    }
}
