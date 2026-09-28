<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WszystkieSzkiceTest extends TestCase
{
    use RefreshDatabase;

    public static function counts(): array
    {
        return [[0], [1], [3], [4], [7], [21]];
    }

    #[DataProvider('counts')]
    public function test_z_dodaj_mozna_dojsc_do_kazdego_wlasnego_szkicu(int $count): void
    {
        $user = $this->user();
        $drafts = Recipe::factory()->draft()->count($count)->create(['author_id' => $user->id]);
        $foreign = Recipe::factory()->draft()->create(['title' => 'Cudzy tajny szkic']);
        $response = $this->actingAs($user)->get(route('add'))->assertOk();
        if ($count === 0) {
            $response->assertDontSee('Wszystkie szkice');
        } else {
            $response->assertSee('Wszystkie szkice');
        }
        $seen = [];
        $url = '/dodaj/szkice';
        do {
            $page = $this->get($url)->assertOk()->assertDontSee($foreign->title);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
            $xpath = new \DOMXPath($dom);
            foreach (self::elementyDom($xpath->query('//main//a[contains(@href, "szkic=")]')) as $link) {
                $seen[] = $link->getAttribute('href');
                $this->get($link->getAttribute('href'))->assertOk();
            }
            $url = $xpath->evaluate('string(//main//a[normalize-space(.)="Pokaż więcej"]/@href)');
        } while ($url !== '');
        $this->assertCount($count, array_unique($seen));
        foreach ($drafts as $draft) {
            $this->assertContains(route('recipes.create', ['szkic' => $draft->id]), $seen);
        }
        $this->get(route('recipes.create', ['szkic' => $foreign->id]))->assertForbidden();
    }

    public function test_gosc_nie_widzi_listy(): void
    {
        $this->get('/dodaj/szkice')->assertRedirect(route('login'));
    }

    public function test_kreator_szkicu_uzywa_stalego_adresu_takze_po_wejsciu_przez_nazwe(): void
    {
        $draft = Recipe::factory()->draft()->create(['author_id' => ($user = $this->user())->id]);
        $this->actingAs($user)->get(route('recipes.details', $draft->slug))
            ->assertRedirect(route('recipes.create', ['szkic' => $draft->id]));
        $foreign = Recipe::factory()->draft()->create();
        $this->get(route('recipes.details', $foreign->slug))->assertForbidden();
    }
}
