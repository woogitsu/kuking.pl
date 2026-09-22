<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reprodukcja #567, przygotowana bez wykonania. Tylko osobna baza tego zadania. */
final class NiedostepnyPrzepis567Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_DATABASE') !== 'kuking_test_audyt567') {
            throw new \RuntimeException('Uruchom tylko z jawnym DB_DATABASE=kuking_test_audyt567 i zweryfikowanym osobnym polaczeniem.');
        }
        parent::setUp();
    }

    public function test_jedyny_zapisany_przepis_po_zmianie_na_prywatny_nie_udaje_pustego_zeszytu(): void
    {
        $owner = $this->user('zeszyt567');
        $author = $this->user('autor567');
        $collection = Collection::create([
            'owner_id' => $owner->getKey(),
            'name' => 'Na niedziele 567',
            'visibility' => 'private',
        ]);
        $recipe = Recipe::factory()->create([
            'author_id' => $author->getKey(),
            'visibility' => 'public',
            'title' => 'Rozpoznawalny przepis kontrolny 567',
            'slug' => 'rozpoznawalny-przepis-kontrolny-567',
        ]);
        $collection->recipes()->attach($recipe->getKey());
        $url = route('collections.show', $collection);

        $visible = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertStringContainsString($recipe->title, $this->collectionText($visible->getContent()));

        $recipe->update(['visibility' => 'private']);
        $hidden = $this->get($url)->assertOk();
        $text = $this->collectionText($hidden->getContent());
        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $collection->getKey(),
            'recipe_id' => $recipe->getKey(),
        ]);
        $this->assertStringNotContainsString($recipe->title, $text);
        $this->assertStringNotContainsString(
            'W tym zeszycie nic jeszcze nie ma',
            $text,
            'Zapis nadal istnieje, tylko stracil widocznosc: ekran nie moze twierdzic, ze nigdy nic tu nie bylo.',
        );
    }

    public function test_naprawa_nie_usuwa_prawdziwego_pustego_stanu(): void
    {
        $owner = $this->user('pusty567');
        $collection = Collection::create([
            'owner_id' => $owner->getKey(),
            'name' => 'Naprawde pusty 567',
            'visibility' => 'private',
        ]);
        $response = $this->actingAs($owner)->get(route('collections.show', $collection))->assertOk();
        $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $this->collectionText($response->getContent()));
    }

    private function collectionText(string $html): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = (new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " marka-zeszyt ")]');
        $this->assertSame(1, $nodes->length, 'Wymagany rzeczywisty obszar zeszytu, bez naglowka dokumentu i szyn.');

        return $nodes->item(0)->textContent;
    }
}
