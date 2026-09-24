<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingZachowujeWyborTest extends TestCase
{
    use RefreshDatabase;

    public function test_szukanie_zachowuje_wybor_poza_wynikami_i_pozwala_go_odznaczyc(): void
    {
        $viewer = $this->user('widz');
        $this->user('halina');
        $this->actingAs($viewer)->get(route('onboarding.people'))->assertOk();
        $context = session('onboarding.selection');
        $response = $this->get(route('onboarding.people', [
            'q' => 'marek', 'follow' => ['halina'], 'selection' => $context['token'] ?? '',
        ]))->assertOk();
        $response->assertSee('Wcześniej wybrane osoby');
        $this->assertMatchesRegularExpression('/name="follow\[\]"[^>]*value="halina"[^>]*checked/', $response->getContent());
        $this->assertDatabaseCount('follows', 0);
        $this->get(route('onboarding.done'))->assertOk();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_wybor_nie_pokazuje_niedostepnego_profilu_obok_dostepnego(): void
    {
        $viewer = $this->user('widz');
        $this->user('halina');
        $hidden = $this->user('ukryta');
        $viewer->blocking()->attach($hidden->id, ['created_at' => now()]);
        $this->actingAs($viewer)->get(route('onboarding.people'));
        $this->get(route('onboarding.people', ['follow' => ['halina', 'ukryta'], 'selection' => session('onboarding.selection.token')]))
            ->assertOk()->assertSee('value="halina"', false)->assertDontSee('value="ukryta"', false);
    }

    public function test_wybor_nie_przechodzi_na_drugie_konto_ani_po_terminie(): void
    {
        $viewer = $this->user('widz');
        $other = $this->user('drugi');
        $this->user('halina');
        $this->actingAs($viewer)->get(route('onboarding.people'));
        $query = ['follow' => ['halina'], 'selection' => session('onboarding.selection.token')];
        $this->actingAs($other)->get(route('onboarding.people', $query))->assertOk()
            ->assertSee('Zaznacz je ponownie')->assertDontSee('value="halina"', false);
        $query['selection'] = session('onboarding.selection.token');
        $this->travel(31)->minutes();
        $this->get(route('onboarding.people', $query))->assertOk()->assertSee('Zaznacz je ponownie')
            ->assertDontSee('value="halina"', false);
    }

    public function test_po_walidacji_odtwarza_wyslany_wybor_zamiast_starego_adresu(): void
    {
        $this->actingAs($this->user('widz'))->get(route('onboarding.people'));
        $this->user('halina');
        $this->user('marek');
        $context = session('onboarding.selection.token');
        $this->withSession(['_old_input' => ['follow' => ['marek'], 'selection' => $context]])
            ->get(route('onboarding.people', ['follow' => ['halina'], 'selection' => $context]))
            ->assertOk()->assertSee('value="marek"', false)->assertDontSee('value="halina"', false);
    }
}
