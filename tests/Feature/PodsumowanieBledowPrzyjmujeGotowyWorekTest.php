<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `x-error-summary` i `x-field` czytają błędy z nazwanego worka
 * (`$errors->getBag(...)`) od czasu osobnych worków błędów 2FA. Wyszukiwarka
 * i krok onboardingu „ludzie" walidują jednak frazę z GET własnym walidatorem
 * i przekazują do podsumowania gotowy `MessageBag`, który worków nie ma.
 * Po scaleniu obu zmian `/szukaj` i `/witaj/ludzie` kończyły się błędem 500
 * przy KAŻDYM wyświetleniu, także bez żadnego błędu.
 */
class PodsumowanieBledowPrzyjmujeGotowyWorekTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyszukiwarka_bez_bledu_renderuje_sie(): void
    {
        $this->get(route('search', ['q' => 'zupa']))->assertOk();
    }

    public function test_wyszukiwarka_pokazuje_blad_zbyt_dlugiej_frazy_w_podsumowaniu(): void
    {
        $this->get(route('search', ['q' => str_repeat('a', 121)]))
            ->assertOk()
            ->assertSee('error-summary', false)
            ->assertSee('do 120 znaków', false);
    }

    public function test_onboarding_ludzie_bez_bledu_i_z_bledem_renderuje_sie(): void
    {
        $osoba = $this->user('basia');

        $this->actingAs($osoba)->get(route('onboarding.people'))->assertOk();

        $this->actingAs($osoba)
            ->get(route('onboarding.people', ['q' => str_repeat('a', 121)]))
            ->assertOk()
            ->assertSee('error-summary', false)
            ->assertSee('do 120 znaków', false);
    }
}
