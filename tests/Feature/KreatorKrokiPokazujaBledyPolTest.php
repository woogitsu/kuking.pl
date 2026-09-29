<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kroki 1–3 kreatora są anonimowymi komponentami Blade (#1387, punkt 5).
 * Błędy walidacji Livewire mają nadal dochodzić do `@error` i `x-field`
 * wewnątrz nich — komunikat pola ma być widoczny na swoim kroku, z
 * `aria-invalid` i powiązaniem `aria-describedby` z komunikatem.
 */
final class KreatorKrokiPokazujaBledyPolTest extends TestCase
{
    use RefreshDatabase;

    private function widoczny(Testable $c, string $klucz): void
    {
        $komunikat = $c->errors()->first($klucz);

        $this->assertNotSame('', (string) $komunikat, 'Brak błędu dla '.$klucz);
        $c->assertSeeHtml(e($komunikat));
    }

    public function test_blad_pola_jest_widoczny_na_kazdym_z_trzech_krokow(): void
    {
        $c = Livewire::actingAs(User::factory()->create())->test('recipe-wizard');

        // Krok 1: pole `x-field` w komponencie, brak tytułu.
        $c->call('next')->assertSet('step', 1)->assertHasErrors('form.title');
        $this->widoczny($c, 'form.title');
        $c->assertSeeHtml('aria-invalid="true"')->assertSeeHtml('f-form-title-error');

        // Krok 1: pole wyboru z własnym `@error`.
        $c->set('form.title', 'Zupa')->set('form.difficulty', 'zzz')->call('next')->assertHasErrors('form.difficulty');
        $this->widoczny($c, 'form.difficulty');
        $c->assertSeeHtml('aria-describedby="f-form-difficulty-error"');

        $c->set('form.difficulty', 'easy')->call('next')->assertSet('step', 2);

        // Krok 2: składnik za długi.
        $c->set('ingredients.0.text', str_repeat('a', 500))->call('next')->assertSet('step', 2)->assertHasErrors('ingredients.0.text');
        $this->widoczny($c, 'ingredients.0.text');
        $c->assertSeeHtml('f-ingredients-0-text-error');

        $c->set('ingredients.0.text', 'mąka')->call('next')->assertSet('step', 3);

        // Krok 3: instrukcja za długa.
        $c->set('steps.0.instruction', str_repeat('a', 4001))->call('next')->assertSet('step', 3)->assertHasErrors('steps.0.instruction');
        $this->widoczny($c, 'steps.0.instruction');
        $c->assertSeeHtml('f-steps-0-instruction-error');
    }
}
