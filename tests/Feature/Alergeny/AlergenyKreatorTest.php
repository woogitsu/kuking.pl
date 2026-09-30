<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Recipes\Alergeny\Alergen;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sekcja „Alergeny” w kreatorze przepisu (#1902): 14 pól, potwierdzenie, błąd po polsku,
 * brak sekcji przy wyłączonej fladze, `needs_review` z przyciskiem „Składniki nadal się zgadzają”.
 *
 * KONTROLA UJEMNA (ręcznie): (1) usunięcie `validateAlergeny()` z `publish()` oblewa
 * `test_publikacja_z_zaznaczeniem_bez_potwierdzenia_daje_blad_przy_polu`; (2) usunięcie
 * synchronizacji `alergenyPotwierdzone = false` po `needs_review` w `persist()` oblewa
 * `test_zmiana_skladnika_po_potwierdzeniu_daje_do_przegladu_a_autozapis_go_nie_odnawia`;
 * (3) zdjęcie warunku `alergenyWlaczone()` z widoku oblewa testy flagi.
 */
final class AlergenyKreatorTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.alergeny.wlaczone' => true]);
        $this->autor = $this->user('autor_kreatora_alergenow');
    }

    /** Kreator doprowadzony do kroku 2 z wypełnionym przepisem (gotowy do publikacji). */
    private function kreator(): Testable
    {
        return Livewire::actingAs($this->autor)->test('recipe-wizard')
            ->set('form.title', 'Naleśniki babci')
            ->set('form.visibility', 'public')
            ->set('ingredients.0.text', '200 g mąki pszennej')
            ->set('ingredients.1.text', '2 jajka')
            ->set('steps.0.instruction', 'Usmaż na patelni.')
            ->set('step', 2);
    }

    public function test_krok_skladnikow_pokazuje_czternascie_pol_i_potwierdzenie(): void
    {
        $k = $this->kreator();

        foreach (Alergen::cases() as $alergen) {
            $k->assertSeeHtml('value="'.$alergen->value.'"');
            $k->assertSee($alergen->etykieta());
        }
        $k->assertSee('Składniki sprawdzone — zaznaczone alergeny to wszystkie, o których wiem');
        $k->assertSee('To Twoje zaznaczenie, nie badanie.');
    }

    public function test_przy_wylaczonej_fladze_nie_ma_sekcji_i_nie_ma_zapisu(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);

        $k = $this->kreator();
        $k->assertDontSee('Składniki sprawdzone')->assertDontSeeHtml('id="f-alergeny"');

        // Nawet podstawione w stanie pola nic nie zapisują, gdy flaga jest wyłączona.
        $k->set('alergeny', ['milk'])->set('alergenyPotwierdzone', true)->call('publish');

        $przepis = Recipe::query()->sole();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_publikacja_z_potwierdzonymi_alergenami_zapisuje_deklaracje(): void
    {
        $this->kreator()
            ->set('alergeny', ['milk', 'gluten'])
            ->set('alergenyPotwierdzone', true)
            ->set('step', 4)
            ->call('publish')
            ->assertHasNoErrors();

        $przepis = Recipe::query()->sole();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['gluten', 'milk'], $przepis->allergens);
        $this->assertTrue($przepis->isPublished());
    }

    public function test_publikacja_z_zaznaczeniem_bez_potwierdzenia_daje_blad_przy_polu(): void
    {
        $k = $this->kreator()
            ->set('alergeny', ['milk'])
            ->set('step', 4)
            ->call('publish')
            ->assertHasErrors('alergeny')
            ->assertSet('step', 2);

        // Błąd po polsku, mówi co zrobić, widoczny przy polu ORAZ w podsumowaniu.
        $komunikat = (string) $k->errors()->first('alergeny');
        $this->assertStringContainsString('Zaznacz pole „Składniki sprawdzone”', $komunikat);
        $html = $k->html();
        $this->assertSame(2, substr_count($html, e($komunikat)), 'Komunikat ma stać przy polu i w podsumowaniu.');
        $this->assertStringContainsString('id="f-alergeny-error"', $html);

        // Zaznaczenie zostało w formularzu (poprawne dane nie znikają), a przepis nie jest opublikowany
        // z oznaczeniem, którego autor nie potwierdził.
        $k->assertSet('alergeny', ['milk']);
        $przepis = Recipe::query()->sole();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertFalse($przepis->isPublished());
    }

    public function test_autozapis_z_zaznaczeniem_bez_potwierdzenia_zapisuje_reszte_i_nie_krzyczy(): void
    {
        $k = $this->kreator()->set('alergeny', ['milk'])->call('next');

        $k->assertHasNoErrors();
        $przepis = Recipe::query()->sole();
        $this->assertSame('Naleśniki babci', $przepis->title);
        $this->assertSame('unchecked', $przepis->allergen_status);
    }

    public function test_zmiana_skladnika_po_potwierdzeniu_daje_do_przegladu_a_autozapis_go_nie_odnawia(): void
    {
        $k = $this->kreator()->set('alergeny', ['eggs'])->set('alergenyPotwierdzone', true)->call('saveDraft');
        $this->assertSame('declared', Recipe::query()->sole()->allergen_status);

        $k->set('ingredients.1.text', '2 jajka i szklanka mleka')->call('saveDraft');

        $przepis = Recipe::query()->sole();
        $this->assertSame('needs_review', $przepis->allergen_status);
        $k->assertSet('alergenyPotwierdzone', false)->assertSet('alergenyStan', 'needs_review');
        $k->assertSee('Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.');
        $k->assertSee('Składniki nadal się zgadzają');

        // Kolejny autozapis (np. po literze w innym polu) nie wraca do `declared`.
        $k->set('form.summary', 'Cienkie, na mleku')->call('saveDraft');
        $this->assertSame('needs_review', Recipe::query()->sole()->allergen_status);
    }

    public function test_publikacja_przepisu_w_stanie_do_przegladu_nie_jest_blokowana(): void
    {
        $k = $this->kreator()->set('alergeny', ['eggs'])->set('alergenyPotwierdzone', true)->call('saveDraft');
        $k->set('ingredients.1.text', 'coś innego')->call('saveDraft');

        $k->set('step', 4)->call('publish')->assertHasNoErrors();

        $przepis = Recipe::query()->sole();
        $this->assertTrue($przepis->isPublished());
        $this->assertSame('needs_review', $przepis->allergen_status);
    }

    public function test_przycisk_skladniki_nadal_sie_zgadzaja_potwierdza_ponownie(): void
    {
        $k = $this->kreator()->set('alergeny', ['eggs'])->set('alergenyPotwierdzone', true)->call('saveDraft');
        $k->set('ingredients.1.text', 'coś innego')->call('saveDraft');
        $this->assertSame('needs_review', Recipe::query()->sole()->allergen_status);

        $k->call('potwierdzAlergenyPonownie')->assertHasNoErrors()
            ->assertSet('alergenyStan', 'declared')->assertSet('alergenyPotwierdzone', true);

        $przepis = Recipe::query()->sole();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['eggs'], $przepis->allergens);
    }

    public function test_przycisk_ponownego_potwierdzenia_nie_robi_deklaracji_z_niczego(): void
    {
        // Przepis istnieje (autozapis), oznaczenie jest „nie sprawdzono”, a zaznaczenie bez potwierdzenia nie jest deklaracją.
        $k = $this->kreator()->set('alergeny', ['eggs'])->call('next');
        $this->assertSame('unchecked', Recipe::query()->sole()->allergen_status);

        $k->call('potwierdzAlergenyPonownie');

        $przepis = Recipe::query()->sole();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_stan_zapisanego_oznaczenia_nie_da_sie_podmienic_z_przegladarki(): void
    {
        $k = $this->kreator();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $k->set('alergenyStan', 'declared');
    }

    public function test_podpowiedz_ze_slownika_jest_tylko_tekstem_i_niczego_nie_zaznacza(): void
    {
        $k = $this->kreator();

        $k->assertSee('Podpowiedź: w „200 g mąki pszennej” widzimy gluten.');
        $k->assertSee('zaznacz to pole, jeśli to prawda');
        $k->assertSet('alergeny', [])->assertSet('alergenyPotwierdzone', false);

        // Publikacja z samą podpowiedzią, bez kliknięcia autora, niczego nie oznacza.
        $k->set('step', 4)->call('publish')->assertHasNoErrors();
        $przepis = Recipe::query()->sole();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_ekran_nigdy_nie_pisze_ze_nic_nie_wykryto(): void
    {
        $k = Livewire::actingAs($this->autor)->test('recipe-wizard')
            ->set('form.title', 'Woda z cytryną')
            ->set('ingredients.0.text', 'woda')
            ->set('step', 2);

        $this->assertDoesNotMatchRegularExpression('/wykryt|nie znaleziono|brak alergen/iu', $k->html());
    }

    public function test_edycja_wczytuje_zapisane_oznaczenie(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey()]);
        $przepis->forceFill([
            'allergen_status' => 'declared',
            'allergens' => ['soy', 'sesame'],
            'allergens_declared_at' => now(),
        ])->save();

        Livewire::actingAs($this->autor)->test('recipe-wizard', ['recipeId' => $przepis->getKey()])
            ->assertSet('alergeny', ['soy', 'sesame'])
            ->assertSet('alergenyPotwierdzone', true)
            ->assertSet('alergenyStan', 'declared');
    }
}
