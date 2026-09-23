<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OpisBezIlosciNieObiecujeSkalowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pomoc_w_formularzu_jednostronicowym_opisuje_to_samo(): void
    {
        $this->actingAs($this->user('opisprosty741'))->get(route('recipes.create.simple'))
            ->assertOk()
            ->assertSee('Bez ilości')
            ->assertSee('Zaznacz, jeśli nie podajesz liczby i jednostki. Sposób dozowania wpisz w nazwie składnika, np. „mleko — ile weźmie”.')
            ->assertDontSee('gdy ktoś przeliczy przepis');
    }

    public function test_pomoc_w_kreatorze_opisuje_wpisanie_skladnika_a_nie_przyszly_przelicznik(): void
    {
        Livewire::actingAs($this->user('opis741'))->test('recipe-wizard')
            ->set('title', 'Ciasto')->call('next')
            ->assertSee('Bez ilości')
            ->assertSee('Zaznacz, jeśli nie podajesz liczby i jednostki. Sposób dozowania wpisz w nazwie składnika, np. „mleko — ile weźmie”.')
            ->assertDontSee('gdy ktoś przeliczy przepis');
    }
}
