<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #745 — `x-field` odtwarzało `old($name, $value)` bez kontroli typu.
 *
 * HTML pozwala przesłać `name[]=coś` tam, gdzie formularz ma zwykły
 * `<input type="text">`. Walidator odrzuca taki wpis (`string`), ale
 * dopiero PO tym, jak trafił do sesji przez `withInput()` — `old('name')`
 * po redirect nadal zwraca tablicę. `{{ $current }}` w Blade wywołuje
 * `e()`, a `htmlspecialchars()` na tablicy rzuca `TypeError`: zamiast
 * błędu przy jednym polu wywalał się render CAŁEJ reszty formularza (500),
 * a poprawnie wypełnione pola (np. opis) znikały razem z nim.
 *
 * Test odtwarza pełny cykl HTTP z `resources/views/pages/collections/index.blade.php`
 * (`x-field name="name"` bez pętli/`:wiersz`) — nie sam render komponentu na
 * sztucznej wartości.
 */
class XFieldOdrzucaTablicowyOldInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_tablicowa_nazwa_zeszytu_nie_wywraca_renderu_po_walidacji(): void
    {
        $user = $this->user('tablicowa_nazwa');

        $odpowiedz = $this->actingAs($user)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => ['Zeszyt'],
                'description' => 'Opis, który ma przetrwać.',
                'visibility' => 'private',
            ]);

        $odpowiedz->assertRedirect(route('collections.index'))
            ->assertSessionHasErrors('name');

        // Krok, który mierzy problem: GET celu przekierowania z tą samą
        // sesją. Bez poprawki ten request kończył się 500.
        $strona = $this->get(route('collections.index'));
        $strona->assertOk();
        $strona->assertSee('Załóż nowy zeszyt');

        // Poprawne pole (opis) nie znika przez to, że sąsiednie pole
        // wróciło z sesji jako tablica.
        $strona->assertSee('Opis, który ma przetrwać.', false);

        $this->assertSame(0, $user->collections()->count());
    }

    public function test_zagniezdzona_tablica_w_nazwie_zeszytu_tez_nie_wywraca_renderu(): void
    {
        $user = $this->user('zagniezdzona_nazwa');

        $this->actingAs($user)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => ['a' => ['b' => 'Zeszyt']],
                'visibility' => 'private',
            ])
            ->assertRedirect(route('collections.index'))
            ->assertSessionHasErrors('name');

        $this->get(route('collections.index'))->assertOk();
    }

    public function test_poprawne_ponowne_wyslanie_nazwy_nadal_dziala(): void
    {
        $user = $this->user('poprawna_nazwa_po_bledzie');

        $this->actingAs($user)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => ['Zeszyt'],
                'visibility' => 'private',
            ])
            ->assertSessionHasErrors('name');

        $this->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => 'Zeszyt na dobre',
                'visibility' => 'private',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->collections()->count());
    }

    /**
     * Drugie wejście tej samej klasy problemu (komentarz do #745):
     * `WierszFormularza::jestAktywny()` i `x-error-summary` rzutowały
     * `old('_wiersz')` na string bez kontroli typu. `_wiersz` jest zwykłym
     * polem ukrytym — nic nie broni wysłania `_wiersz[]=...` — a
     * `CollectionController::selectedCollection()` waliduje `collection_id`,
     * nie `_wiersz`, więc taki wpis dociera do sesji nietknięty.
     */
    public function test_tablicowy_wiersz_nie_wywraca_renderu_karty_zapisu(): void
    {
        $user = $this->user('tablicowy_wiersz');
        $post = Post::factory()->create();

        $this->actingAs($user)
            ->from($post->url())
            ->post(route('collections.save-post', $post), [
                'collection_id' => 'nie-uuid',
                '_wiersz' => ['wpis-'.$post->id],
            ])
            ->assertRedirect($post->url())
            ->assertSessionHasErrors('collection_id');

        // Krok, który mierzy problem: GET celu przekierowania z tą samą
        // sesją, w tym render x-error-summary i x-field wewnątrz
        // resources/views/components/wybor-zeszytu.blade.php.
        $this->get($post->url())->assertOk();

        $this->assertDatabaseCount('collection_items', 0);
    }
}
