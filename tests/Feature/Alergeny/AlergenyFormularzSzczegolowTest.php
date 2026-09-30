<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Recipes\Alergeny\Alergen;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sekcja „Alergeny” w formularzu szczegółów przepisu bez kreatora (#1902): zwykły POST,
 * `novalidate`, błąd po polsku przy polu i w podsumowaniu, dane nie znikają.
 *
 * KONTROLA UJEMNA (ręcznie): (1) zdjęcie `alergeny_formularz` z warunku w
 * `ZapisPrzepisuRequest::deklaracjaAlergenow()` oblewa `test_formularz_bez_znacznika_nie_rusza_oznaczenia`;
 * (2) usunięcie `wymagaPotwierdzenia()` z tej metody oblewa
 * `test_zaznaczenie_bez_potwierdzenia_wraca_z_bledem_przy_polu_i_w_podsumowaniu`;
 * (3) zdjęcie `Rule::in(...)` oblewa `test_nieznany_kod_alergenu_jest_odrzucony`.
 */
final class AlergenyFormularzSzczegolowTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.alergeny.wlaczone' => true]);
        $this->autor = $this->user('autor_formularza_alergenow');
        $this->przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'status' => Recipe::STATUS_DRAFT, 'published_at' => null]);
    }

    /** @param  array<string, mixed>  $dodatkowe */
    private function zapisz(array $dodatkowe = [], ?User $kto = null)
    {
        $dane = [
            'content_revision' => $this->przepis->refresh()->content_revision,
            'title' => 'Naleśniki babci',
            'visibility' => 'private',
            'action' => 'draft',
            'ingredients' => [['text' => '200 g mąki pszennej'], ['text' => '2 jajka']],
            'steps' => [['instruction' => 'Usmaż na patelni.']],
            ...$dodatkowe,
        ];

        return $this->actingAs($kto ?? $this->autor)->from(route('recipes.edit', $this->przepis))
            ->put(route('recipes.update', $this->przepis), $dane);
    }

    public function test_formularz_ma_czternascie_pol_potwierdzenie_i_novalidate(): void
    {
        $html = (string) $this->actingAs($this->autor)->get(route('recipes.edit', $this->przepis))->assertOk()->getContent();

        foreach (Alergen::cases() as $alergen) {
            $this->assertStringContainsString('name="alergeny[]" value="'.$alergen->value.'"', $html);
        }
        $this->assertStringContainsString('name="alergeny_potwierdzone"', $html);
        $this->assertStringContainsString('name="alergeny_formularz"', $html);
        $this->assertStringContainsString('Składniki sprawdzone — zaznaczone alergeny to wszystkie, o których wiem', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*id="formularz-szczegolow"[^>]*novalidate/', $html);
    }

    public function test_przy_wylaczonej_fladze_formularz_nie_ma_sekcji_i_zapis_ignoruje_pola(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);

        $html = (string) $this->actingAs($this->autor)->get(route('recipes.edit', $this->przepis))->getContent();
        $this->assertStringNotContainsString('alergeny_formularz', $html);
        $this->assertStringNotContainsString('Składniki sprawdzone', $html);

        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['milk'], 'alergeny_potwierdzone' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('unchecked', $this->przepis->refresh()->allergen_status);
    }

    public function test_przy_wylaczonej_fladze_nieprawidlowe_pole_alergeny_nie_blokuje_zapisu(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);

        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['foo'], 'alergeny_potwierdzone' => '1', 'title' => 'Naleśniki bez alergenów'])
            ->assertSessionHasNoErrors();

        $przepis = $this->przepis->refresh();
        $this->assertSame('Naleśniki bez alergenów', $przepis->title);
        $this->assertSame('unchecked', $przepis->allergen_status);
    }

    public function test_przy_wlaczonej_fladze_nieznany_alergen_nadal_jest_bledem(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['foo'], 'alergeny_potwierdzone' => '1'])
            ->assertSessionHasErrors('alergeny.0');
    }

    public function test_potwierdzone_alergeny_zapisuja_sie_jako_deklaracja(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['milk', 'eggs', 'milk'], 'alergeny_potwierdzone' => '1'])
            ->assertSessionHasNoErrors();

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['eggs', 'milk'], $przepis->allergens);
    }

    public function test_samo_potwierdzenie_bez_alergenow_to_deklaracja_z_pusta_lista(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny_potwierdzone' => '1'])->assertSessionHasNoErrors();

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_zaznaczenie_bez_potwierdzenia_nie_zapisuje_i_wraca_z_bledem_przy_polu_i_w_podsumowaniu(): void
    {
        $odp = $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['milk', 'gluten'], 'title' => 'Naleśniki po nowemu']);

        $odp->assertRedirect(route('recipes.edit', $this->przepis))->assertSessionHasErrors('alergeny');
        $przepis = $this->przepis->refresh();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_blad_i_wpisane_dane_sa_widoczne_po_nieudanej_walidacji(): void
    {
        $this->followingRedirects();
        $html = (string) $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['milk', 'gluten'], 'title' => 'Naleśniki po nowemu'])->getContent();

        $komunikat = 'Zaznaczone alergeny trzeba potwierdzić. Zaznacz pole „Składniki sprawdzone”, albo odznacz wszystkie alergeny, jeśli nie chcesz ich oznaczać.';
        $this->assertSame(2, substr_count($html, e($komunikat)), 'Komunikat ma stać przy polu i w podsumowaniu błędów.');
        $this->assertStringContainsString('id="f-alergeny-error"', $html);
        $this->assertMatchesRegularExpression('/name="alergeny\[\]" value="milk"\s+checked/', $html, 'Zaznaczone pole zniknęło po nieudanej walidacji.');
        $this->assertMatchesRegularExpression('/name="alergeny\[\]" value="gluten"\s+checked/', $html);
        $this->assertStringContainsString('value="Naleśniki po nowemu"', $html, 'Wpisana nazwa zniknęła po błędzie w alergenach.');
    }

    public function test_formularz_bez_znacznika_nie_rusza_oznaczenia(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['fish'], 'alergeny_potwierdzone' => '1']);
        $this->assertSame('declared', $this->przepis->refresh()->allergen_status);

        // Zapis z innego ekranu (bez sekcji alergenów) nie cofa i nie zmienia oznaczenia.
        $this->zapisz(['title' => 'Naleśniki, wersja druga'])->assertSessionHasNoErrors();

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['fish'], $przepis->allergens);
    }

    public function test_odznaczenie_wszystkiego_razem_z_potwierdzeniem_cofa_oznaczenie(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['fish'], 'alergeny_potwierdzone' => '1']);

        $this->zapisz(['alergeny_formularz' => '1'])->assertSessionHasNoErrors();

        $przepis = $this->przepis->refresh();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_zmiana_skladnika_bez_zmiany_pol_alergenow_daje_do_przegladu(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'alergeny_potwierdzone' => '1']);

        // Formularz odsyła to samo zaznaczone „sprawdzone”, ale składnik się zmienił.
        $this->zapisz([
            'alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'alergeny_potwierdzone' => '1',
            'ingredients' => [['text' => '200 g mąki pszennej'], ['text' => '2 jajka'], ['text' => 'mleko']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('needs_review', $this->przepis->refresh()->allergen_status);

        // Edycja przepisu czekającego na przegląd pokazuje notatkę i wczytaną listę, bez potwierdzenia.
        $html = (string) $this->get(route('recipes.edit', $this->przepis))->getContent();
        $this->assertStringContainsString('Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.', $html);
        $this->assertMatchesRegularExpression('/name="alergeny\[\]" value="eggs"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="alergeny_potwierdzone"[^>]*checked/', $html);
    }

    public function test_zapis_formularza_w_stanie_do_przegladu_bez_zmian_nie_jest_blokowany(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'alergeny_potwierdzone' => '1']);
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'alergeny_potwierdzone' => '1', 'ingredients' => [['text' => 'inna mąka']]]);
        $this->assertSame('needs_review', $this->przepis->refresh()->allergen_status);

        // Ta sama lista, bez pola „sprawdzone” — to nie zmiana, więc bez błędu.
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'ingredients' => [['text' => 'inna mąka']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('needs_review', $this->przepis->refresh()->allergen_status);

        // Zaznaczenie pola „sprawdzone” przy tej samej liście potwierdza ponownie.
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['eggs'], 'alergeny_potwierdzone' => '1', 'ingredients' => [['text' => 'inna mąka']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('declared', $this->przepis->refresh()->allergen_status);
    }

    public function test_nieznany_kod_alergenu_jest_odrzucony(): void
    {
        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['banany'], 'alergeny_potwierdzone' => '1'])
            ->assertSessionHasErrors('alergeny.0');

        $this->assertSame('unchecked', $this->przepis->refresh()->allergen_status);
    }

    public function test_cudzy_przepis_nie_da_sie_oznaczyc_przez_formularz(): void
    {
        $obca = $this->user('obca_osoba_formularza');

        $this->zapisz(['alergeny_formularz' => '1', 'alergeny' => ['milk'], 'alergeny_potwierdzone' => '1'], $obca)->assertForbidden();

        $this->assertSame('unchecked', $this->przepis->refresh()->allergen_status);
    }
}
