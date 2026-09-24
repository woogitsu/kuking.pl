<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Zapis bez publikacji na opublikowanym przepisie zostawia wersję (issue #1316).
 *
 * CO SIĘ DZIAŁO
 * Kreator na opublikowanym przepisie zapisuje przez `PublishRecipe` z
 * `publish: false` — autozapis po ~3 s przerwy i przycisk „Zapisz zmiany".
 * Macierz przejść nie pozwala wrócić do szkicu, więc przepis zostawał
 * publiczny, a nowy krok był widoczny od razu. Migawka w `recipe_versions`
 * powstawała tylko przy `if ($publish)`:
 *
 *     strona przepisu: „gotuj 20 minut"   wersje: 1   wersja 1: „gotuj 10 minut"
 *
 * REGUŁA (`SnapshotRecipeVersion::poprawka()`): ostatnia wersja ma być tym, co
 * widzi czytelnik; kolejne zapisy tej samej osoby w ciągu pół godziny sklejają
 * się w jedną wersję; zapis bez zmian nie tworzy niczego; szkic nie dostaje
 * wersji przed pierwszą publikacją.
 */
class PoprawkaOpublikowanegoPrzepisuMaWersjeTest extends TestCase
{
    use RefreshDatabase;

    private function opublikowanyPrzepis(): Recipe
    {
        $autor = $this->user('autorka');

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => '1 kurczak']],
            'steps' => [['instruction' => 'Gotuj 10 minut.']],
        ])->assertRedirect();

        $przepis = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();
        $this->assertSame(1, $przepis->versions()->count());

        return $przepis;
    }

    /** @return list<string> */
    private function krokiOstatniejWersji(Recipe $przepis): array
    {
        return array_column($przepis->versions()->firstOrFail()->snapshot['steps'], 'instruction');
    }

    public function test_autozapis_kreatora_na_opublikowanym_przepisie_zostawia_wersje_zgodna_z_widokiem(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $kreator = Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        $kreator->set('steps.0.instruction', 'Gotuj 20 minut.')->assertSet('saveState', 'saved');

        $przepis->refresh();
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->status);

        // Czytelnik (gość) widzi nową treść…
        $this->app['auth']->forgetGuards();
        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertSee('Gotuj 20 minut.')->assertDontSee('Gotuj 10 minut.');

        // …i ostatnia wersja w historii to ta sama treść; wersja 1 zostaje.
        $this->assertSame(2, $przepis->versions()->count());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiOstatniejWersji($przepis));
        $this->assertSame(SnapshotRecipeVersion::OPIS_POPRAWKI, $przepis->versions()->firstOrFail()->change_note);
        $this->assertSame(
            ['Gotuj 10 minut.'],
            array_column($przepis->versions()->where('version_number', 1)->firstOrFail()->snapshot['steps'], 'instruction'),
        );
    }

    public function test_kolejne_autozapisy_skleja_sie_a_zapis_bez_zmian_nic_nie_dodaje(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $kreator = Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        foreach (['Gotuj 15 minut.', 'Gotuj 18 minut.', 'Gotuj 20 minut.'] as $tekst) {
            $kreator->set('steps.0.instruction', $tekst)->assertSet('saveState', 'saved');
        }
        $kreator->call('saveDraft')->assertSet('saveState', 'saved');

        $this->assertSame(2, $przepis->versions()->count(), 'Autozapisy jednej poprawki mają dać jedną wersję.');
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiOstatniejWersji($przepis));
    }

    public function test_po_oknie_sklejania_poprawka_dostaje_nowa_wersje(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $kreator = Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        $kreator->set('steps.0.instruction', 'Gotuj 20 minut.');

        $this->travel(SnapshotRecipeVersion::OKNO_SKLEJANIA_MINUT + 1)->minutes();

        Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->set('steps.0.instruction', 'Gotuj 25 minut.')->assertSet('saveState', 'saved');

        $this->assertSame(3, $przepis->versions()->count());
        $this->assertSame([3, 2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 25 minut.'], $this->krokiOstatniejWersji($przepis));
        $this->assertSame(
            ['Gotuj 20 minut.'],
            array_column($przepis->versions()->where('version_number', 2)->firstOrFail()->snapshot['steps'], 'instruction'),
        );
    }

    public function test_zapis_szkicu_z_formularza_bez_javascriptu_tez_zostawia_wersje(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $this->actingAs($przepis->author)->put(route('recipes.update', $przepis->slug), [
            'action' => 'draft',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => '1 kurczak']],
            'steps' => [['instruction' => 'Gotuj 20 minut.']],
        ])->assertRedirect();

        $this->assertSame(2, $przepis->versions()->count());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiOstatniejWersji($przepis));
    }

    /** Kontrola dodatnia: szkic przed pierwszą publikacją nie ma wersji, publikacja daje wersję 1. */
    public function test_szkic_nie_dostaje_wersji_przed_publikacja(): void
    {
        $kreator = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Zupa na poniedziałek')
            ->set('steps.0.instruction', 'Zagotuj wodę.')
            ->assertSet('saveState', 'saved');

        $przepis = Recipe::findOrFail($kreator->get('recipeId'));
        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
        $this->assertSame(0, $przepis->versions()->count());

        $kreator->call('publish');

        $this->assertSame([1], $przepis->versions()->pluck('version_number')->all());
    }
}
