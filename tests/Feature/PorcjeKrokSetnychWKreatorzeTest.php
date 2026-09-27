<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Krok porcji w KREATORZE Livewire — druga droga zapisu (#750).
 *
 * STAN ZASTANY
 * `PorcjeKrokSetnychTest` wyrównał `step="0.01"` i `decimal:0,2` tylko
 * w zwykłym formularzu POST (jednostronicowy i edycja). Kreator
 * (`recipe-wizard`) został przy `step="0.5"` i walidacji bez limitu miejsc
 * po przecinku: szkic z 1,25 był dla przeglądarki `stepMismatch`, a 1,255
 * zapisywało się po cichu jako 1,26 (kolumna `decimal(6,2)`). Zapis
 * kreatora idzie `wire:click`, nie natywnym wysłaniem formularza, więc
 * przeglądarka niczego tu nie blokowała.
 *
 * DECYZJA (ta sama co dla formularza, właściciel 20.09.2026): setne.
 * Ta sama macierz wartości ma dawać ten sam wynik na obu drogach.
 */
final class PorcjeKrokSetnychWKreatorzeTest extends TestCase
{
    use RefreshDatabase;

    public static function macierz(): array
    {
        return [
            'połówka' => ['0.5', 0.5],
            'całość' => ['1', 1.0],
            'setne' => ['1.25', 1.25],
            'półtorej' => ['1.5', 1.5],
            'górna granica' => ['999', 999.0],
            'poniżej minimum' => ['0.49', null],
            'powyżej maksimum' => ['999.5', null],
            'trzy miejsca po przecinku' => ['1.255', null],
        ];
    }

    #[DataProvider('macierz')]
    public function test_kreator_przyjmuje_i_odrzuca_to_samo_co_formularz(string $wpisane, ?float $zapisane): void
    {
        $wKreatorze = $this->user('porcje750');
        $kreator = Livewire::actingAs($wKreatorze)->test('recipe-wizard')
            ->set('title', 'Zupa krem z dyni')
            ->assertSet('saveState', 'saved')
            ->set('servings', $wpisane)
            // Wpisana wartość nie znika — ani po przyjęciu, ani po odrzuceniu.
            ->assertSet('servings', $wpisane);

        $wFormularzu = $this->actingAs($this->user('porcje750b'))
            ->post(route('recipes.store'), [
                'title' => 'Zupa krem z dyni',
                'skladniki_tekst' => '',
                'przygotowanie_tekst' => 'Ugotuj dynię do miękkości i zblenduj z bulionem.',
                'visibility' => 'public',
                'action' => 'publish',
                'servings' => $wpisane,
            ]);

        if ($zapisane === null) {
            $kreator->assertHasErrors('servings')->assertSet('saveState', 'error');
            $wFormularzu->assertSessionHasErrors('servings');
            $this->assertNull(Recipe::where('author_id', $wKreatorze->getKey())->sole()->servings, "Kreator zapisał {$wpisane}, choć formularz to odrzuca.");

            return;
        }

        $kreator->assertHasNoErrors('servings')->assertSet('saveState', 'saved');
        $wFormularzu->assertSessionHasNoErrors();
        $this->assertSame($zapisane, (float) Recipe::where('author_id', $wKreatorze->getKey())->sole()->servings);
    }

    public function test_komunikat_przy_trzech_miejscach_mowi_co_wpisac(): void
    {
        Livewire::actingAs($this->user('porcje750'))->test('recipe-wizard')
            ->set('title', 'Zupa krem z dyni')
            ->set('servings', '1.255')
            ->assertHasErrors('servings')
            ->assertSee('Zamiast 1,255 wpisz 1,25 albo 1,26.');
    }

    public function test_zapisane_setne_otwieraja_sie_w_kreatorze_jako_poprawna_wartosc(): void
    {
        $autor = $this->user('porcje750');
        $szkic = Recipe::factory()->draft()->for($autor, 'author')->create(['servings' => 1.25]);

        $kreator = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->assertSet('servings', '1.25');

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$kreator->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $pole = (new DOMXPath($dom))->query('//input[@name="servings"]')->item(0);

        $this->assertNotNull($pole, 'Brak pola porcji w kreatorze.');
        $this->assertSame('1.25', $pole->getAttribute('value'));
        // 1.25 musi leżeć na siatce kroku, inaczej przeglądarka zgłasza stepMismatch.
        $this->assertSame('0.01', $pole->getAttribute('step'), 'step kreatora nie zgadza się z decimal:0,2.');
        $this->assertSame('0.5', $pole->getAttribute('min'));

        // Zapis bez zmiany liczby porcji przechodzi i niczego nie zaokrągla.
        $kreator->set('summary', 'Na chłodne dni.')->assertHasNoErrors()->assertSet('saveState', 'saved');
        $this->assertSame(1.25, (float) $szkic->fresh()->servings);
    }
}
