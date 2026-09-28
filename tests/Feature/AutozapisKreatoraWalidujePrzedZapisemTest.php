<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutozapisKreatoraWalidujePrzedZapisemTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwszy_autozapis_nie_wysyla_181_znakowego_tytulu_do_bazy(): void
    {
        $tekst = str_repeat('a', 181);
        $component = Livewire::actingAs($this->user('autozapis528'))
            ->test('recipe-wizard')->set('title', $tekst)
            ->assertHasErrors('title')->assertSet('title', $tekst)
            ->assertSet('recipeId', null)->assertSet('saveState', 'error')
            ->assertSee('Skróć ją do 180 znaków.')
            ->assertSee('Nie zapisaliśmy tych zmian.');
        $this->assertDatabaseCount('recipes', 0);
        $this->assertStringNotContainsString('Szkic zapisany.', $component->get('saveMessage'));
        // Odczyt z rzeczywistego inputu, nie z echo w innej części strony.
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
        $xpath = new \DOMXPath($doc);
        $this->assertSame($tekst, self::elementDom($xpath->query('//input[@id="f-title"]')->item(0))->getAttribute('value'));
        $this->assertSame('true', self::elementDom($xpath->query('//input[@id="f-title"]')->item(0))->getAttribute('aria-invalid'));
        $component->set('title', '')->assertHasNoErrors('title')->assertSet('saveState', 'waiting');
        $this->assertDatabaseCount('recipes', 0);
        $component->set('title', str_repeat('a', 180))->assertHasNoErrors('title')->assertSet('saveState', 'saved');
        $this->assertSame(str_repeat('a', 180), Recipe::sole()->title);
    }

    public static function granice(): array
    {
        return [
            'tytuł' => ['title', str_repeat('a', 181), 'Dobry tytuł'],
            'opis' => ['summary', str_repeat('a', 2001), 'Dobry opis'],
            'osoba' => ['source_person', str_repeat('a', 121), 'Od babci'],
            'historia' => ['source_note', str_repeat('a', 2001), 'Historia'],
            'długi adres' => ['source_url', 'https://example.com/'.str_repeat('a', 2001), 'https://example.com/'],
            'niedokończony adres' => ['source_url', 'https://', 'https://example.com/'],
            'porcje' => ['servings', '1000', '4'],
            'przygotowanie' => ['prep_minutes', '10081', '10'],
            'gotowanie' => ['cook_minutes', '-1', '20'],
            'rok' => ['family_since_year', '2101', '2000'],
            'trudność' => ['difficulty', 'inna', 'easy'],
            'widoczność' => ['visibility', 'inna', 'private'],
            'pochodzenie' => ['source_type', 'inne', 'family'],
            'składnik' => ['ingredients.0.text', str_repeat('a', 241), 'Mleko'],
            'grupa' => ['ingredients.0.group_name', str_repeat('a', 121), 'Ciasto'],
            'uwaga' => ['ingredients.0.note', str_repeat('a', 301), 'Świeże'],
            'krok' => ['steps.0.instruction', str_repeat('a', 4001), 'Gotuj.'],
            'minutnik' => ['steps.0.timer_minutes', '10081', '5'],
        ];
    }

    #[DataProvider('granice')]
    public function test_bledna_aktualizacja_zostawia_caly_tekst_i_poprzedni_dobry_szkic(string $pole, string $zle, string $dobrze): void
    {
        $component = Livewire::actingAs($this->user('granice528'))->test('recipe-wizard')
            ->set('title', 'Poprzedni dobry szkic')->set('summary', 'Poprzedni opis')
            ->set('ingredients.0.text', 'Mleko')->set('steps.0.instruction', 'Podgrzej.')
            ->assertSet('saveState', 'saved');
        $recipe = Recipe::sole();
        $before = $this->snapshot();
        $step = $component->get('step');
        $component->set($pole, $zle)->assertHasErrors($pole)
            ->assertSet($pole, $zle)->assertSet('saveState', 'error')
            ->assertSet('recipeId', $recipe->getKey())->assertSet('step', $step)
            ->assertSee('Cały tekst jest nadal w formularzu.');
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('Szkic zapisany.', $component->get('saveMessage'));
        $component->call('saveDraft')->assertHasErrors($pole)->assertSet($pole, $zle);
        $this->assertSame($before, $this->snapshot());
        $component->set($pole, $dobrze)->assertHasNoErrors($pole)->assertSet('saveState', 'saved');
        $this->assertDatabaseCount('recipes', 1);
        $this->assertSame($recipe->getKey(), Recipe::sole()->getKey());
    }

    public function test_dalej_nie_chowa_blednego_wiersza_a_poprawka_odblokowuje_przejscie(): void
    {
        $component = Livewire::actingAs($this->user('dalej528'))->test('recipe-wizard')
            ->set('title', 'Dobry szkic')->set('step', 2)
            ->set('ingredients.0.note', str_repeat('a', 301))
            ->call('next')->assertSet('step', 2)->assertHasErrors('ingredients.0.note');
        $component->set('ingredients.0.note', 'Dobra uwaga')->call('next')->assertSet('step', 3)->assertHasNoErrors();
    }

    public static function powroty(): array
    {
        return [
            'składnik' => [2, 'ingredients.0.text', str_repeat('a', 241)],
            'instrukcja' => [3, 'steps.0.instruction', str_repeat('a', 4001)],
        ];
    }

    #[DataProvider('powroty')]
    public function test_po_cofnieciu_dalej_pozwala_dotrzec_do_blednego_pola(int $step, string $pole, string $tekst): void
    {
        $component = Livewire::actingAs($this->user('powrot528'))->test('recipe-wizard')
            ->set('title', 'Dobry szkic')->set('step', $step);
        $before = $this->snapshot();
        $component->set($pole, $tekst)->assertSet('step', $step)
            ->call('back')->assertSet('step', $step - 1)
            ->call('next')->assertSet('step', $step)->assertHasErrors($pole)
            ->assertSet($pole, $tekst)->assertSet('saveState', 'error');
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return [
            Recipe::sole()->getRawOriginal(),
            RecipeIngredient::orderBy('id')->get()->map->getRawOriginal()->all(),
            RecipeStep::orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }
}
