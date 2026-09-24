<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Świadomy zapis opublikowanego przepisu zostawia NOWĄ wersję (issue #1316).
 *
 * CO SIĘ DZIAŁO
 * Kreator na opublikowanym przepisie zapisuje przez `PublishRecipe` z
 * `publish: false`. Macierz przejść nie pozwala wrócić do szkicu, więc
 * przepis zostawał publiczny, a nowy krok był widoczny od razu. Migawka
 * w `recipe_versions` powstawała tylko przy `if ($publish)`:
 *
 *     strona przepisu: „gotuj 20 minut"   wersje: 1   wersja 1: „gotuj 10 minut"
 *
 * Pierwsza poprawka sklejała autozapisy w oknie 30 minut — nadpisując
 * `snapshot` istniejącej wersji. DECYZJA WŁAŚCICIELA (24.09.2026): wersji
 * nigdy nie nadpisujemy. Nowa wersja powstaje przy „Zapisz zmiany" i przy
 * wyjściu z kreatora; autozapis (pola, „Dalej", „Wstecz") wersji nie tworzy;
 * zapis bez zmian nie tworzy niczego; szkic nie ma wersji przed publikacją.
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
    private function krokiWersji(Recipe $przepis, int $numer): array
    {
        return array_column($przepis->versions()->where('version_number', $numer)->firstOrFail()->snapshot['steps'], 'instruction');
    }

    public function test_autozapis_kreatora_nie_tworzy_wersji(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $kreator = Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        foreach (['Gotuj 15 minut.', 'Gotuj 18 minut.', 'Gotuj 20 minut.'] as $tekst) {
            $kreator->set('steps.0.instruction', $tekst)->assertSet('saveState', 'saved');
        }
        $kreator->call('next')->call('back');

        // Treść jest zapisana i widoczna od razu…
        $this->app['auth']->forgetGuards();
        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertSee('Gotuj 20 minut.');

        // …ale historia ma tylko wersję z publikacji, nietkniętą.
        $this->assertSame([1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 10 minut.'], $this->krokiWersji($przepis, 1));
    }

    /** Kontrola dodatnia do poprzedniego: ten sam kreator, ale świadomy zapis — wersja jest. */
    public function test_zapisz_zmiany_tworzy_nowa_wersje_zgodna_z_widokiem(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->set('steps.0.instruction', 'Gotuj 20 minut.')
            ->call('saveDraft')
            ->assertSet('saveState', 'saved');

        $przepis->refresh();
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->status);
        $this->assertSame([2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiWersji($przepis, 2));
        $this->assertSame(SnapshotRecipeVersion::OPIS_POPRAWKI, $przepis->versions()->firstOrFail()->change_note);
        $this->assertSame(['Gotuj 10 minut.'], $this->krokiWersji($przepis, 1));
    }

    public function test_zapisz_zmiany_po_autozapisie_w_tym_samym_zadaniu_tez_tworzy_wersje(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        // `update()` na komponencie wysyła zmianę pola i wywołanie metody
        // jednym żądaniem — tak jak przeglądarka po kliknięciu w trakcie debounce.
        Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->update(calls: [['method' => 'saveDraft', 'params' => []]], updates: ['steps.0.instruction' => 'Gotuj 20 minut.'])
            ->assertSet('saveState', 'saved');

        $this->assertSame([2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiWersji($przepis, 2));
    }

    public function test_wyjscie_z_kreatora_tworzy_wersje(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->set('steps.0.instruction', 'Gotuj 20 minut.')
            ->call('wyjdz')
            ->assertRedirect(route('home'));

        $this->assertSame([2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiWersji($przepis, 2));
    }

    public function test_istniejaca_wersja_nigdy_nie_zmienia_tresci(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $kreator = Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
        $kreator->set('steps.0.instruction', 'Gotuj 20 minut.')->call('saveDraft');
        $druga = $przepis->versions()->where('version_number', 2)->firstOrFail();

        // Ta sama osoba, ta sama minuta — dawniej to się „sklejało" w wersję 2.
        $kreator->set('steps.0.instruction', 'Gotuj 25 minut.')->call('saveDraft');

        $this->assertSame([3, 2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 10 minut.'], $this->krokiWersji($przepis, 1));
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiWersji($przepis, 2));
        $this->assertSame(['Gotuj 25 minut.'], $this->krokiWersji($przepis, 3));
        $this->assertEquals($druga->snapshot, $druga->fresh()->snapshot);

        // Zapis bez zmian nie mnoży wersji.
        $kreator->call('saveDraft')->assertSet('saveState', 'saved');
        $this->assertSame(3, $przepis->versions()->count());
    }

    public function test_model_odmawia_nadpisania_wersji(): void
    {
        $wersja = $this->opublikowanyPrzepis()->versions()->firstOrFail();

        try {
            $wersja->update(['snapshot' => ['steps' => []]]);
            $this->fail('Nadpisanie wersji przepisu powinno się nie udać.');
        } catch (\LogicException) {
        }

        $this->assertSame('Gotuj 10 minut.', $wersja->fresh()->snapshot['steps'][0]['instruction']);
    }

    public function test_zapis_szkicu_z_formularza_bez_javascriptu_tworzy_wersje(): void
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

        $this->assertSame([2, 1], $przepis->versions()->pluck('version_number')->all());
        $this->assertSame(['Gotuj 20 minut.'], $this->krokiWersji($przepis, 2));
    }

    /** Kontrola dodatnia: szkic przed pierwszą publikacją nie ma wersji, publikacja daje wersję 1. */
    public function test_szkic_nie_dostaje_wersji_przed_publikacja(): void
    {
        $kreator = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Zupa na poniedziałek')
            ->set('steps.0.instruction', 'Zagotuj wodę.')
            ->call('saveDraft')
            ->assertSet('saveState', 'saved');

        $przepis = Recipe::findOrFail($kreator->get('recipeId'));
        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
        $this->assertSame(0, $przepis->versions()->count());

        $kreator->call('publish');

        $this->assertSame([1], $przepis->versions()->pluck('version_number')->all());
    }
}
