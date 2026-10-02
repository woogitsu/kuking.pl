<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #2677 (ciąg dalszy #2572): autor może w kreatorze usunąć informację
 * „Źródło podaje łącznie: …”. Usunięcie dzieje się przy zapisie, a do tego
 * czasu „Przywróć” je cofa.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie kreatora i bazy, nie tekst źródeł.
 */
final class UsuniecieCzasuZrodlaWKreatorzeTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(?int $czas = 90): Recipe
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => null, 'cook_minutes' => null, 'czas_laczny_zrodla_minut' => $czas]);
        $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => '200 g mąki']);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Zrób ciasto.']);

        return $przepis;
    }

    private function kreator(Recipe $przepis): Testable
    {
        return Livewire::actingAs($przepis->author)->test('recipe-wizard', ['recipeId' => $przepis->id]);
    }

    public function test_krok_pokazuje_informacje_i_przycisk_gdy_wartosc_jest(): void
    {
        $this->kreator($this->przepis())
            ->assertSee('Źródło podaje łącznie: około 1 godz. 30 min.', false)
            ->assertSee('Usuń tę informację')
            ->assertSeeHtml('wire:click="usunCzasZrodla"')
            ->assertDontSee('Przywróć');
    }

    public function test_bez_wartosci_nie_ma_informacji_ani_przycisku(): void
    {
        $this->kreator($this->przepis(null))
            ->assertDontSee('Źródło podaje łącznie')
            ->assertDontSee('Usuń tę informację');
    }

    public function test_usuniecie_dziala_dopiero_przy_zapisie_a_strona_traci_zdanie(): void
    {
        $przepis = $this->przepis();

        $kreator = $this->kreator($przepis)->call('usunCzasZrodla')
            ->assertSet('czasZrodlaDoUsuniecia', true)
            ->assertSee('Przywróć')
            ->assertDontSee('Usuń tę informację');
        $this->assertSame(90, $przepis->fresh()->czas_laczny_zrodla_minut, 'Przed zapisem nic nie znika z bazy.');

        $kreator->call('saveDraft')->assertSet('saveState', 'saved')
            ->assertSet('czasZrodlaMinuty', null)
            ->assertDontSee('Usuń tę informację');

        $this->assertNull($przepis->fresh()->czas_laczny_zrodla_minut);
        $this->app['auth']->forgetGuards();
        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertDontSee('Źródło podaje łącznie');
    }

    public function test_przywroc_cofa_usuniecie_i_zapis_zostawia_wartosc(): void
    {
        $przepis = $this->przepis();

        $this->kreator($przepis)->call('usunCzasZrodla')->call('przywrocCzasZrodla')
            ->assertSet('czasZrodlaDoUsuniecia', false)
            ->assertSee('Usuń tę informację')
            ->call('saveDraft');

        $this->assertSame(90, $przepis->fresh()->czas_laczny_zrodla_minut);
    }

    public function test_zwykly_zapis_bez_klikniecia_nie_czysci_wartosci(): void
    {
        $przepis = $this->przepis();

        $this->kreator($przepis)->set('form.title', 'Zmieniona nazwa')->call('saveDraft');

        $this->assertSame(90, $przepis->fresh()->czas_laczny_zrodla_minut);
    }

    public function test_usuniecie_bez_wartosci_nic_nie_ustawia(): void
    {
        $this->kreator($this->przepis(null))->call('usunCzasZrodla')->assertSet('czasZrodlaDoUsuniecia', false);
    }

    public function test_cudzy_przepis_nie_otwiera_kreatora_i_nie_traci_wartosci(): void
    {
        $przepis = $this->przepis();
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get(route('recipes.edit', $przepis))->assertForbidden();

        $this->assertSame(90, $przepis->fresh()->czas_laczny_zrodla_minut);
    }
}
