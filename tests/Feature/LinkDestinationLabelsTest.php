<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkDestinationLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_recipe_breadcrumb_names_the_actual_feed_in_html_and_json_ld(): void
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $this->user('autor')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $xpath = $this->xpath($this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent());
        $links = $xpath->query('//ol[contains(@class,"okruchy")]/li[2]/a');
        $this->assertCount(1, $links);
        $this->assertSame('Świeżo z Kuking', trim($links->item(0)->textContent));
        $this->assertSame(route('discover'), $links->item(0)->attributes->getNamedItem('href')->nodeValue);
        $breadcrumbs = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
            if (($data['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumbs[] = $data;
            }
        }
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('Świeżo z Kuking', $breadcrumbs[0]['itemListElement'][1]['name']);
        $this->assertSame(route('discover'), $breadcrumbs[0]['itemListElement'][1]['item']);
    }

    public function test_empty_notebook_index_opens_recipe_search(): void
    {
        $response = $this->actingAs($this->user('czytelnik'))->get(route('collections.index'))->assertOk();
        $this->assertSearchLink($response->getContent());
    }

    public function test_empty_notebook_opens_recipe_search(): void
    {
        $owner = $this->user('wlasciciel');
        $collection = Collection::create(['owner_id' => $owner->getKey(), 'name' => 'Na potem', 'visibility' => 'private']);
        $response = $this->actingAs($owner)->get(route('collections.show', $collection))->assertOk();
        $this->assertSearchLink($response->getContent());
    }

    private function assertSearchLink(string $html): void
    {
        $links = $this->xpath($html)->query('//main//a[normalize-space(.)="Poszukaj przepisów"]');
        $this->assertCount(1, $links);
        $this->assertSame(route('search', ['sekcja' => 'przepisy']), $links->item(0)->attributes->getNamedItem('href')->nodeValue);
    }

    public function test_recipe_search_without_phrase_keeps_scope_and_invites_query(): void
    {
        $response = $this->get(route('search', ['sekcja' => 'przepisy']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//main//form[@method="GET"]//input[@name="sekcja" and @value="przepisy"]'));
        $this->assertCount(1, $xpath->query('//main//input[@name="q" and @value=""]'));
        $response->assertSee('Wpisz coś w pole powyżej i kliknij');
    }
}
