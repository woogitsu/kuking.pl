<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #1387, krok 9 — uwaga z kroku 8: `publish()` po błędzie z
 * `validateExistingStepIds()` ustawiał krok 3 dla KAŻDEGO klucza błędu,
 * także dla `publikacja`, którego pole stoi na podglądzie (krok 4).
 *
 * „Opublikuj” klika się na podglądzie. Gdy przepis zniknął w innej karcie,
 * człowiek był cofany na krok „przygotowanie”, którego to błąd nie dotyczy;
 * komunikat czytał tylko w podsumowaniu błędów, a jego odnośnik odsyłał
 * z powrotem na podgląd. Teraz kreator zostaje tam, gdzie stoi pole błędu.
 */
class PublikacjaZBledemPrzepisuZostajeNaWlasciwymKrokuTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function usuniety_w_innej_karcie_przepis_zostawia_czlowieka_na_podgladzie(): void
    {
        $autor = $this->user();
        $przepis = Recipe::factory()->draft()->create(['author_id' => $autor->id]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Zagotuj wodę.']);
        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->set('step', 4);

        $this->actingAs($autor)->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();

        $komponent->call('publish')
            ->assertSet('step', 4)
            ->assertHasErrors(['publikacja'])
            ->assertSee('Skopiuj wpisany tekst');

        $this->assertSoftDeleted($przepis);
        $this->assertSame(1, Recipe::withTrashed()->count(), 'Nie wolno tworzyć zastępczego przepisu.');
    }

    #[Test]
    public function powtorzony_zapisany_krok_nadal_odsyla_na_krok_przygotowania(): void
    {
        $autor = $this->user();
        $przepis = Recipe::factory()->draft()->create(['author_id' => $autor->id]);
        $krok = $przepis->steps()->create(['position' => 0, 'instruction' => 'Zagotuj wodę.']);
        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->set('step', 4);

        $komponent->set('steps', [
            ['_key' => 'a', 'id' => $krok->id, 'instruction' => 'Zagotuj wodę.', 'timer_minutes' => '', 'mediaId' => null, 'photo' => null],
            ['_key' => 'b', 'id' => $krok->id, 'instruction' => 'Zagotuj wodę drugi raz.', 'timer_minutes' => '', 'mediaId' => null, 'photo' => null],
        ]);
        $komponent->set('step', 4)->call('publish')
            ->assertSet('step', 3)
            ->assertHasErrors(['steps.1.instruction']);
    }
}
