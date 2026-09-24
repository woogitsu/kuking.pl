<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #977: po wdrożeniu karta sprzed wdrożenia dostaje 419, a
 * `resources/js/strona-nieaktualna.js` pokazuje polski komunikat. Czy tekst
 * może powiedzieć „szkic zapisany wcześniej zostaje”, rozstrzyga WYŁĄCZNIE
 * `data-kreator-zapis` z ostatniego udanego renderu kreatora. Gdyby atrybut
 * mówił „szkic” przed pierwszym zapisem, komunikat obiecałby bezpieczeństwo
 * danych, których nie ma w bazie — a po odświeżeniu formularz byłby pusty.
 *
 * Zachowanie w przeglądarce (prawdziwy klient Livewire, 419, brak confirm()):
 * `scripts/przegladarka/strona-nieaktualna.test.mjs`.
 */
class KreatorWystawiaStanZapisuDlaStronyNieaktualnejTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nowy_kreator_bez_zapisu_mowi_brak_a_po_zapisie_szkic(): void
    {
        $komponent = Livewire::actingAs($this->user())->test('recipe-wizard');

        $this->assertStringContainsString('data-kreator-zapis="brak"', $komponent->html());

        // Pierwszy udany zapis tworzy szkic — dopiero od tej chwili wolno
        // obiecać, że szkic zostaje.
        $komponent->set('title', 'Zupa z koperkiem');

        $this->assertSame(1, Recipe::count(), 'Kontrola dodatnia: szkic naprawdę jest w bazie.');
        $this->assertStringContainsString('data-kreator-zapis="szkic"', $komponent->html());
    }

    #[Test]
    public function zbyt_krotka_nazwa_nie_zapisuje_wiec_zostaje_brak(): void
    {
        $komponent = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Zu');

        $this->assertSame(0, Recipe::count());
        $this->assertStringContainsString('data-kreator-zapis="brak"', $komponent->html());
    }

    #[Test]
    public function opublikowany_przepis_w_edycji_mowi_opublikowany(): void
    {
        $autor = $this->user();
        $przepis = Recipe::factory()->create(['author_id' => $autor->id]);

        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->id]);

        $this->assertStringContainsString('data-kreator-zapis="opublikowany"', $komponent->html());
    }
}
