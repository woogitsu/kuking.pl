<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Czas wykonania 0 minut na karcie „Ugotowałem" (issue #768).
 *
 * Formularz i walidacja dopuszczają `actual_minutes = 0` (min:0).
 * Wartość 0 jest poprawną informacją (np. mniej niż minuta / przygotowanie
 * bez dodatkowego czasu) i jest eksportowana w RODO.
 * Karta nie ma prawa gubić zera z powodu luźnego warunku `@if($event->actual_minutes)`
 * zamiast `@if($event->actual_minutes !== null)`.
 * „Poprawne dane nigdy nie znikają" (AGENTS.md §5).
 */
class CzasWykonaniaZeroMinutTest extends TestCase
{
    use RefreshDatabase;

    public function test_karta_wykonania_wyswietla_czas_zero_minut(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            actualMinutes: 0,
        );

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Zajęło mi 0 min');
    }

    public function test_formularz_przyjmuje_zero_minut_i_pokazuje_je_na_karcie(): void
    {
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), [
                'actual_minutes' => 0,
            ]);

        $response->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->latest('id')->firstOrFail();
        $this->assertSame(0, $event->actual_minutes);

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Zajęło mi 0 min');
    }

    public function test_karta_wykonania_wyswietla_czas_dodatni(): void
    {
        $kucharz = $this->user('kucharz2');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            actualMinutes: 15,
        );

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Zajęło mi 15 min');
    }

    public function test_karta_nie_pokazuje_odznaki_gdy_brak_czasu(): void
    {
        $kucharz = $this->user('kucharz3');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            actualMinutes: null,
        );

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertDontSee('Zajęło mi');
    }
}
