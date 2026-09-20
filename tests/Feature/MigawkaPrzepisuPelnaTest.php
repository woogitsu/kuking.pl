<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigawkaPrzepisuPelnaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migawki_zachowuja_adres_i_obie_wartosci_bez_ilosci(): void
    {
        $author = $this->user();
        $recipe = null;
        foreach ([false, true] as $noAmount) {
            $recipe = app(PublishRecipe::class)->handle($author,
                ['title' => 'Sól do smaku', 'source_type' => 'external', 'source_url' => $noAmount ? null : 'https://example.invalid/przepis'],
                [['text' => 'sól', 'no_amount' => $noAmount]], [['instruction' => 'Dodaj sól.']], true, $recipe);
        }
        $versions = $recipe->versions()->reorder('version_number')->get();
        $first = $versions[0]->snapshot;
        $second = $versions[1]->snapshot;
        $this->assertArrayHasKey('source_url', $first);
        $this->assertSame('https://example.invalid/przepis', $first['source_url']);
        $this->assertArrayHasKey('source_url', $second);
        $this->assertNull($second['source_url']);
        $this->assertArrayHasKey('no_amount', $first['ingredients'][0]);
        $this->assertFalse($first['ingredients'][0]['no_amount']);
        $this->assertTrue($second['ingredients'][0]['no_amount']);
        $this->assertNotSame($first, $second);
        $this->assertNull($first['ingredients'][0]['quantity']);
        $this->assertNull($second['ingredients'][0]['quantity']);
    }

    public function test_stara_migawka_nie_dostaje_dzisiejszych_wartosci(): void
    {
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Stary przepis'], [], [['instruction' => 'Gotuj.']], true);
        $version = $recipe->versions()->first();
        $legacy = ['title' => 'Dawna treść', 'ingredients' => [['text' => 'sól']]];
        $version->update(['snapshot' => $legacy]);
        app(PublishRecipe::class)->handle($author, ['title' => 'Nowa treść', 'source_url' => 'https://example.invalid'], [['text' => 'sól', 'no_amount' => true]], [['instruction' => 'Gotuj.']], true, $recipe);
        $this->assertSame($legacy, $version->fresh()->snapshot);
    }
}
