<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Issue #1387, krok 8 — regresja. Dawna mapa kroków w komponencie
 * (`stepForKey()`) rozpoznawała składniki tylko po `ingredients.` (z kropką),
 * a przygotowanie po samym przedrostku `steps`. Klucz błędu BEZ numeru wiersza
 * — `ingredients` (pole nad listą składników, krok 2) — spadał do reguły
 * domyślnej i odsyłał na krok 1; tak samo `sprawdzilemOdczyt` (pole na
 * podglądzie, krok 4). Odnośnik w podsumowaniu błędów byłby wtedy zwykłym
 * `href` do pola, którego na widocznym kroku nie ma.
 *
 * Kreator nie dodaje dziś żadnego z tych kluczy do worka błędów, więc to
 * usterka utajona — ale pierwsza zmiana, która taki błąd doda, trafiłaby
 * na nią bez ostrzeżenia.
 */
class NawigacjaKreatoraKluczeBezWierszaTest extends TestCase
{
    use RefreshDatabase;

    public function test_blad_calej_listy_skladnikow_prowadzi_do_kroku_drugiego(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test('recipe-wizard');

        $this->assertSame(2, $component->instance()->stepForKey('ingredients'));

        $component->call('jumpToError', 'ingredients')->assertSet('step', 2);
    }

    public function test_pole_sprawdzenia_odczytu_prowadzi_do_podgladu(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test('recipe-wizard');

        $this->assertSame(4, $component->instance()->stepForKey('sprawdzilemOdczyt'));

        $component->call('jumpToError', 'sprawdzilemOdczyt')->assertSet('step', 4);
    }
}
