<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wycofanie opcjonalnej oceny w formularzu „Ugotowałem" (issue #767).
 *
 * Formularz obiecuje, że oceny są opcjonalne. Jeśli użytkownik przypadkowo
 * kliknie opcję w grupie radio (would_make_again lub perceived_difficulty),
 * musi mieć widoczną i czytelną drogę powrotu do „Nie podaję" bez resetowania
 * całego formularza (który może już zawierać wpisane notatki i zdjęcia).
 */
class WycofanieOcenyWykonaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_zawiera_opcje_nie_podaje_dla_obu_grup(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->get(route('cooked.create', $recipe->slug));

        $response->assertOk();
        // Sprawdź czy obie grupy mają widoczną opcję "Nie podaję"
        $response->assertSee('name="would_make_again" value=""', false);
        $response->assertSee('name="perceived_difficulty" value=""', false);
        $response->assertSee('Nie podaję');
    }

    public function test_wybranie_nie_podaje_zapisuje_null(): void
    {
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), [
                'would_make_again' => '',
                'perceived_difficulty' => '',
                'note' => 'Moja notatka',
            ]);

        $response->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->latest('id')->firstOrFail();
        $this->assertNull($event->would_make_again);
        $this->assertNull($event->perceived_difficulty);
        $this->assertSame('Moja notatka', $event->note);
    }

    public function test_odpowiedz_negatywna_zapisuje_false(): void
    {
        $kucharz = $this->user('kucharz2');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), [
                'would_make_again' => '0',
            ]);

        $response->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->latest('id')->firstOrFail();
        $this->assertFalse($event->would_make_again);

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Raczej nie powtórzę');
    }

    public function test_odpowiedz_pozytywna_zapisuje_true(): void
    {
        $kucharz = $this->user('kucharz3');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), [
                'would_make_again' => '1',
            ]);

        $response->assertRedirect();

        $event = CookedEvent::where('user_id', $kucharz->getKey())->latest('id')->firstOrFail();
        $this->assertTrue($event->would_make_again);

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee('Zrobię ponownie');
    }

    public function test_po_bledzie_innego_pola_wybor_nie_podaje_jest_zachowany(): void
    {
        $kucharz = $this->user('kucharz4');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        // Note powyżej limitu 2000 znaków wywołuje błąd walidacji
        $response = $this->actingAs($kucharz)
            ->from(route('cooked.create', $recipe->slug))
            ->followingRedirects()
            ->post(route('cooked.store', $recipe->slug), [
                'note' => str_repeat('a', 2001),
                'would_make_again' => '',
                'perceived_difficulty' => '',
            ]);

        $response->assertOk();
        $response->assertSee('name="would_make_again" value="" checked', false);
        $response->assertSee('name="perceived_difficulty" value="" checked', false);
    }

    public function test_po_bledzie_innego_pola_konkretna_ocena_jest_zachowana(): void
    {
        $kucharz = $this->user('kucharz5');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($kucharz)
            ->from(route('cooked.create', $recipe->slug))
            ->followingRedirects()
            ->post(route('cooked.store', $recipe->slug), [
                'note' => str_repeat('a', 2001),
                'would_make_again' => '0',
                'perceived_difficulty' => 'easy',
            ]);

        $response->assertOk();
        $response->assertSee('name="would_make_again" value="0" checked', false);
        $response->assertSee('name="perceived_difficulty" value="easy" checked', false);
    }
}
