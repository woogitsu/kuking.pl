<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387: kryterium „ponowna autoryzacja i `#[Locked]` z identyfikatora
 * przepisu nadal czerwieni test”. Test jest behawioralny; jego kontrole
 * ujemne (zdjęcie `Gate::authorize` z `existingRecipe()` i `#[Locked]`
 * z `$recipeId`) stoją w `scripts/kontrole-negatywne-alfa08.py`.
 */
class KreatorPilnujeAutoryzacjiIIdentyfikatoraPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public static function akcje(): array
    {
        return [['autosave'], ['saveDraft'], ['publish']];
    }

    /** Dwa żądania po kolei: przepis zmienia właściciela między nimi. */
    #[DataProvider('akcje')]
    public function test_zapis_ponownie_sprawdza_prawo_do_przepisu_przy_kazdym_zadaniu(string $akcja): void
    {
        $autor = $this->user('basia');
        $obcy = $this->user('obcy');
        $przepis = Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'summary' => 'Stary opis.']);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Zagotuj wodę.']);

        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->getKey()]);

        // Wejście przeszło autoryzację; teraz przepis należy już do kogoś innego.
        $przepis->forceFill(['author_id' => $obcy->getKey()])->save();

        if ($akcja === 'autosave') {
            $komponent->set('form.summary', 'Nowy opis od byłego autora.');
        } else {
            $komponent->call($akcja);
        }

        $komponent->assertForbidden();
        $this->assertSame('Stary opis.', $przepis->fresh()->summary);
        $this->assertSame(1, Recipe::count());
    }

    public function test_klient_nie_podmieni_identyfikatora_przepisu(): void
    {
        $autor = $this->user('basia');
        $cudzy = Recipe::factory()->draft()->create(['author_id' => $this->user('obcy')->getKey()]);

        $komponent = Livewire::actingAs($autor)->test('recipe-wizard');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $komponent->set('recipeId', $cudzy->getKey());
    }
}
