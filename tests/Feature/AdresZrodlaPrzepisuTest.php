<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdresZrodlaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public static function addresses(): array
    {
        return ['https' => ['https://example.invalid/przepis', true], 'http' => ['http://example.invalid/przepis', true],
            'ftp' => ['ftp://example.invalid/przepis', false], 'ssh' => ['ssh://example.invalid/przepis', false],
            'javascript' => ['javascript:alert(1)', false]];
    }

    #[DataProvider('addresses')]
    public function test_nowy_adres_w_formularzu_jest_strona(string $url, bool $allowed): void
    {
        $this->actingAs($this->user());
        $data = ['title' => 'Nowy przepis', 'visibility' => 'public', 'source_type' => 'external', 'source_url' => $url, 'przygotowanie_tekst' => 'Wymieszaj.'];
        $response = $this->from(route('recipes.create'))->post(route('recipes.store'), $data);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseHas('recipes', ['source_url' => $url]);
        } else {
            $response->assertSessionHasErrors('source_url')->assertSessionHasInput('title', 'Nowy przepis')->assertSessionHasInput('source_url', $url);
            $this->assertDatabaseCount('recipes', 0);
        }
    }

    public function test_stary_adres_mozna_zachowac_ale_nie_zmienic_na_inny_ftp(): void
    {
        $author = $this->user();
        // Syntetyczny stan istniejących danych, nie pomiar liczby takich adresów na produkcji.
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Stary przepis', 'source_type' => 'external', 'source_url' => 'ftp://example.invalid/stary'], [], [['instruction' => 'Gotuj.']], true);
        $this->actingAs($author);
        $data = ['title' => 'Poprawiony tytuł', 'visibility' => 'public', 'source_type' => 'external', 'source_url' => $recipe->source_url, 'steps' => [['instruction' => 'Gotuj.']]];
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertSame('ftp://example.invalid/stary', $recipe->fresh()->source_url);
        $data['source_url'] = 'ftp://example.invalid/nowy';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasErrors('source_url');
        $this->assertSame('ftp://example.invalid/stary', $recipe->fresh()->source_url);
        $data['source_url'] = 'http://example.invalid/nowy';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertSame($data['source_url'], $recipe->fresh()->source_url);
        $data['source_url'] = '';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertNull($recipe->fresh()->source_url);
    }

    #[DataProvider('addresses')]
    public function test_tylko_strona_internetowa_jest_linkowana(string $url, bool $allowed): void
    {
        $recipe = Recipe::factory()->zeZdjeciem()->create(['source_type' => 'external', 'source_url' => $url]);
        $html = $this->get(route('recipes.show', $recipe))->assertOk()->getContent();
        $href = 'href="'.e($url).'"';
        $this->assertSame($allowed, str_contains($html, $href), 'Nieprawidłowa obecność linku '.$href);
        if (! $allowed) {
            $this->assertStringContainsString(e($url), $html);
        }
        // Dane strukturalne mówią to samo co widoczny link: adres strony albo nic.
        preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $bloki);
        $przepis = collect($bloki[1])->map(fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->firstWhere('@type', 'Recipe');
        $this->assertNotNull($przepis, 'Strona nie oddała bloku JSON-LD typu Recipe.');
        $this->assertSame($allowed ? $url : null, $przepis['isBasedOn'] ?? null);
    }

    #[DataProvider('addresses')]
    public function test_nowy_adres_w_kreatorze_jest_strona(string $url, bool $allowed): void
    {
        $component = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Przepis z kreatora')->set('source_type', 'external')
            ->assertSet('saveState', 'saved')
            ->set('source_url', $url)->assertSet('source_url', $url);
        if ($allowed) {
            $component->assertHasNoErrors('source_url')->assertSet('saveState', 'saved');
            $this->assertSame($url, Recipe::sole()->source_url);
        } else {
            // Autozapis nie zapisuje odrzuconego adresu; tekst zostaje w polu.
            $component->assertHasErrors('source_url')->assertSet('saveState', 'error')
                ->assertSee('Wklej adres strony zaczynający się od http:// lub https://.')
                ->assertSet('title', 'Przepis z kreatora');
            $this->assertNull(Recipe::sole()->source_url);
            $component->call('saveDraft')->assertHasErrors('source_url');
            $this->assertNull(Recipe::sole()->source_url);
        }
    }

    public function test_kreator_zachowuje_stary_adres_ale_nie_przyjmuje_nowego_ftp(): void
    {
        $author = $this->user();
        // Syntetyczny stan istniejących danych, jak w teście formularza wyżej.
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Stary przepis', 'source_type' => 'external', 'source_url' => 'ftp://example.invalid/stary'], [], [['instruction' => 'Gotuj.']], true);
        $component = Livewire::actingAs($author)->test('recipe-wizard', ['recipeId' => $recipe->getKey()])
            ->assertSet('source_url', 'ftp://example.invalid/stary')
            ->set('title', 'Poprawiony tytuł')->assertHasNoErrors()->assertSet('saveState', 'saved');
        $this->assertSame(['Poprawiony tytuł', 'ftp://example.invalid/stary'], [$recipe->fresh()->title, $recipe->fresh()->source_url]);
        $component->set('source_url', 'ftp://example.invalid/nowy')->assertHasErrors('source_url')->assertSet('saveState', 'error');
        $this->assertSame('ftp://example.invalid/stary', $recipe->fresh()->source_url);
        $component->set('source_url', 'https://example.invalid/nowy')->assertHasNoErrors('source_url')->assertSet('saveState', 'saved');
        $this->assertSame('https://example.invalid/nowy', $recipe->fresh()->source_url);
        // Raz porzucony dawny adres nie wraca jako wyjątek.
        $component->set('source_url', 'ftp://example.invalid/stary')->assertHasErrors('source_url');
        $this->assertSame('https://example.invalid/nowy', $recipe->fresh()->source_url);
    }
}
