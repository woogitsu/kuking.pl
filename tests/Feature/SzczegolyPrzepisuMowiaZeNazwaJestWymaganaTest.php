<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class SzczegolyPrzepisuMowiaZeNazwaJestWymaganaTest extends TestCase
{
    use RefreshDatabase;

    public function test_puste_kroki_blokuja_publikacje_i_edycje_ale_nie_szkic(): void
    {
        $autor = $this->user('kroki');
        $this->actingAs($autor);
        $data = ['title' => 'Zupa bez krokow', 'przygotowanie_tekst' => '', 'visibility' => 'public', 'action' => 'publish'];
        $this->post(route('recipes.store'), $data)->assertSessionHasErrors('przygotowanie_tekst');
        $this->assertDatabaseMissing('recipes', ['title' => $data['title']]);
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $przepis->steps()->create(['position' => 1, 'instruction' => 'Gotuj wodę.']);
        $this->put(route('recipes.update', $przepis), $data)->assertSessionHasErrors('przygotowanie_tekst');
        $this->assertSame('Gotuj wodę.', $przepis->fresh()->steps->first()->instruction);
        $data['action'] = 'draft';
        $this->post(route('recipes.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('recipes', ['title' => $data['title'], 'status' => Recipe::STATUS_DRAFT]);
        $html = $this->get(route('recipes.create'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertSame(1, (new DOMXPath($dom))->query('//main//textarea[@name="przygotowanie_tekst" and @required]')->length);
    }

    public function test_zapis_zmian_w_kreatorze_nie_nazywa_opublikowanego_przepisu_szkicem(): void
    {
        $autor = $this->user('zmiany');
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $przepis->steps()->create(['position' => 1, 'instruction' => 'Gotuj wodę.']);
        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        $html = $komponent->html();
        $this->assertStringContainsString('Zapisz zmiany', $html);
        $this->assertStringNotContainsString('Zapisz szkic', $html);
        $this->assertStringNotContainsString('Szkic zostaje na Twoim koncie', $html);
        $komponent->call('saveDraft')->assertSet('saveMessage', 'Zmiany zapisane.');
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->fresh()->status);
        $szkic = Livewire::actingAs($autor)->test('recipe-wizard')->set('title', 'Nowy szkic bez kroków')->call('saveDraft');
        $szkic->assertSet('saveMessage', 'Szkic zapisany.');
    }

    public function test_kreator_rozroznia_opublikowany_przepis_i_nowy_szkic(): void
    {
        $autor = $this->user('kreator');
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $this->actingAs($autor);
        $html = $this->get(route('recipes.details', $przepis))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $wstep = $xpath->query('//main/h1/following-sibling::p[1]')->item(0);
        $this->assertNotNull($wstep);
        $this->assertStringContainsString('Nazwa przepisu jest wymagana.', $wstep->textContent);
        $this->assertStringContainsString('co najmniej jeden krok przygotowania', $wstep->textContent);
        $badge = $xpath->query('//main//p[contains(@class,"autosave-badge")][1]')->item(0);
        $this->assertNotNull($badge);
        $this->assertStringContainsString('Zmiany zapisują się po drodze.', $badge->textContent);
        $this->assertStringNotContainsString('Szkic zapisze się', $badge->textContent);
        $szkic = Recipe::factory()->for($autor, 'author')->draft()->create();
        $this->get(route('recipes.create', ['szkic' => $szkic->id]))->assertOk()->assertSee('Szkic zapisze się, kiedy podasz nazwę przepisu.');
    }

    public function test_wstep_zgadza_sie_z_wymagana_nazwa_i_odmowa_zapisu_bez_niej(): void
    {
        $autor = $this->user('nazwa');
        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Zupa jarzynowa']);
        $this->actingAs($autor);

        foreach ([route('recipes.edit', $przepis), route('recipes.create.simple')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new DOMXPath($dom);
            $wstep = $xpath->query('//main/h1/following-sibling::p[1]')->item(0);
            $this->assertNotNull($wstep);
            $this->assertStringContainsString('Nazwa przepisu jest wymagana.', $wstep->textContent);
            $this->assertStringContainsString('co najmniej jeden krok przygotowania', $wstep->textContent);
            $this->assertStringContainsString('Pozostałe szczegóły są opcjonalne', $wstep->textContent);
            $this->assertSame(1, $xpath->query('//main//input[@name="title" and @required]')->length);
        }

        $this->from(route('recipes.edit', $przepis))
            ->put(route('recipes.update', $przepis), ['title' => '', 'summary' => 'Opis do zachowania'])
            ->assertSessionHasErrors('title')
            ->assertSessionHasInput('summary', 'Opis do zachowania');
        $this->assertSame('Zupa jarzynowa', $przepis->fresh()->title);
    }
}
