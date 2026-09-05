<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Raczej nie powtórzę" musi dojechać do bazy (audyt A22).
 *
 * `CookedEventController::store()` robiło:
 *
 *     $request->boolean('would_make_again') ?: null
 *
 * Formularz wysyła `value="0"`, więc `false` wpadało w `?:` i zamieniało się
 * w `null` — czyli w „nie zaznaczono". Świadoma negatywna opinia znikała
 * w drodze, a przygotowany render „Raczej nie powtórzę" w karcie wykonania
 * był kodem, który nigdy się nie wykonał.
 *
 * DLACZEGO TO BOLI BARDZIEJ, NIŻ WYGLĄDA
 * „Ugotowałem" to najcenniejszy sygnał jakości przepisu w całym Kuking
 * (AGENTS.md §1). Sygnał, z którego da się zapisać tylko „tak", nie jest
 * sygnałem jakości — jest licznikiem pochwał.
 *
 * Stany są TRZY i test pilnuje każdego z osobna:
 *
 *   zaznaczone „tak"   → true
 *   zaznaczone „nie"   → false
 *   nic nie zaznaczone → null
 *
 * Sklejenie dwóch ostatnich jest właśnie tym błędem, więc `assertNull`
 * i `assertFalse` muszą tu stać obok siebie — `assertFalsy` niczego by
 * nie złapało.
 */
class OpiniaOPrzepisieTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        return Recipe::factory()->create(['author_id' => $this->user('autorka')->getKey()]);
    }

    public function test_zaznaczone_nie_zapisuje_sie_jako_false(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = $this->przepis();

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), ['would_make_again' => '0'])
            ->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->firstOrFail();

        $this->assertNotNull($event->would_make_again, 'Zaznaczone „nie" nie może zamienić się w „nie zaznaczono".');
        $this->assertFalse($event->would_make_again);
    }

    public function test_zaznaczone_tak_zapisuje_sie_jako_true(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = $this->przepis();

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), ['would_make_again' => '1'])
            ->assertRedirect();

        $this->assertTrue(CookedEvent::where('user_id', $kucharz->getKey())->firstOrFail()->would_make_again);
    }

    public function test_brak_zaznaczenia_zostaje_pustka(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = $this->przepis();

        // Trzeci stan musi przeżyć naprawę. „Nie zaznaczono" to nie jest
        // ani pochwała, ani skarga — i nie wolno go zamienić w żadną z nich.
        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug))
            ->assertRedirect();

        $this->assertNull(CookedEvent::where('user_id', $kucharz->getKey())->firstOrFail()->would_make_again);
    }

    public function test_pusty_wybor_z_formularza_tez_zostaje_pustka(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = $this->przepis();

        // Pusty string z formularza przechodzi przez ConvertEmptyStringsToNull.
        // Sprawdzamy to jawnie, bo naprawa opiera się na rozróżnieniu
        // „klucz jest, ale pusty" od „klucz jest i ma 0".
        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), ['would_make_again' => ''])
            ->assertRedirect();

        $this->assertNull(CookedEvent::where('user_id', $kucharz->getKey())->firstOrFail()->would_make_again);
    }

    public function test_karta_wykonania_pokazuje_raczej_nie_powtorze(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = $this->przepis();

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), ['would_make_again' => '0'])
            ->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->firstOrFail();

        // Render dla `false` istniał w cooked-card.blade.php od początku —
        // był tylko nieosiągalny, bo kontroler nigdy nie zapisał `false`.
        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Raczej nie powtórzę', false)
            ->assertDontSee('Zrobię ponownie', false);
    }
}
