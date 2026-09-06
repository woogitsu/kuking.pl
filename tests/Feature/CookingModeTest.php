<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tryb gotowania (issue #24).
 *
 * DLACZEGO TE TESTY SĄ FEATURE, NIE UNIT
 * Najważniejsze wymagania issue („krok przeżywa odświeżenie strony”,
 * „działa bez JavaScriptu”, „widoczność jak strona przepisu”) są z natury
 * ponad-warstwowe — dotyczą kontrolera, sesji, routingu i Policy naraz.
 * Test jednostkowy jednej metody nie złapałby regresji w żadnym z nich.
 */
class CookingModeTest extends TestCase
{
    use RefreshDatabase;

    /** Przepis z ponumerowanymi krokami „Krok numer N.” i jednym składnikiem. */
    private function przepisZKrokami(User $autor, int $liczbaKrokow = 3, ?int $minutnikNaPierwszym = null): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'szklanka mąki',
            'position' => 0,
        ]);

        for ($i = 0; $i < $liczbaKrokow; $i++) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
                'timer_seconds' => $i === 0 ? $minutnikNaPierwszym : null,
            ]);
        }

        return $recipe;
    }

    public function test_gosc_widzi_tryb_gotowania_publicznego_przepisu(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka1'));

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Krok 1 z 3')
            ->assertSee('Krok numer 1.')
            ->assertSee('szklanka mąki');
    }

    /**
     * Dowód wprost na wymóg zadania: widoczność idzie przez ISTNIEJĄCĄ
     * `RecipePolicy::view()`, nie przez osobny warunek w kontrolerze.
     * Macierz pełnej widoczności (public/followers/private × 5 typów
     * widza) jest w `Visibility/CookingModeWidocznoscTest`; to tu jest
     * krótki, czytelny przykład tego samego mechanizmu.
     */
    public function test_prywatny_przepis_jest_zamkniety_tak_samo_jak_na_stronie_przepisu(): void
    {
        $autor = $this->user('autorka2');
        $obcy = $this->user('obcy2');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'private']);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok.']);

        $this->actingAs($obcy)->get(route('recipes.show', $recipe->slug))->assertForbidden();
        $this->actingAs($obcy)->get(route('cooking.show', $recipe->slug))->assertForbidden();

        $this->actingAs($autor)->get(route('cooking.show', $recipe->slug))->assertOk();
    }

    public function test_przepis_bez_krokow_zawraca_do_strony_przepisu_z_komunikatem(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('autorka3')->getKey()]);

        $this->get(route('cooking.show', $recipe->slug))
            ->assertRedirect(route('recipes.show', $recipe->slug))
            ->assertSessionHas('status');
    }

    /**
     * Kryterium akceptacji z issue wprost: „kroki przechodzą się
     * przyciskami i bez JavaScriptu”. Klient testowy Laravela nigdy nie
     * wykonuje JS-u — to jest DOKŁADNIE ten scenariusz, nie jego namiastka.
     */
    public function test_przechodzenie_krokow_przyciskami_bez_javascriptu(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka4'), 3);

        $krok1 = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]));
        $krok1->assertOk()->assertSee('Krok 1 z 3')->assertSee('Następny krok');
        $krok1->assertDontSee('Poprzedni krok');
        // Sam link istnieje w znaczniku jako zwykłe `<a href>` — bez niego
        // ten test przeszedłby nawet, gdyby przycisk zniknął z widoku.
        $this->assertStringContainsString(
            route('cooking.show', [$recipe->slug, 'krok' => 2]),
            $krok1->getContent(),
        );

        $krok2 = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]));
        $krok2->assertOk()->assertSee('Krok 2 z 3')->assertSee('Poprzedni krok')->assertSee('Następny krok');

        $krok3 = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 3]));
        $krok3->assertOk()->assertSee('Krok 3 z 3')->assertSee('Poprzedni krok');
        $krok3->assertDontSee('Następny krok');
        $krok3->assertSee('To już ostatni krok.');
    }

    public function test_ostatni_krok_pokazuje_ugotowalem_zalogowanemu(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka5'), 1);

        $this->actingAs($this->user('ktos5'))
            ->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Ugotowałem');
    }

    public function test_gosc_na_ostatnim_kroku_widzi_zaloz_konto_zamiast_ugotowalem(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka6'), 1);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Załóż konto')
            ->assertDontSee('Ugotowałem');
    }

    public function test_krok_spoza_zakresu_jest_przycinany_do_najblizszego_istniejacego(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka7'), 3);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 999]))->assertSee('Krok 3 z 3');
        $this->get(route('cooking.show', [$recipe->slug, 'krok' => -5]))->assertSee('Krok 1 z 3');
        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 'abc']))->assertSee('Krok 1 z 3');
    }

    /**
     * Kryterium akceptacji z issue wprost: „odhaczone kroki przeżywają
     * odświeżenie strony”. Dwa osobne `$this->get()` po `$this->post()` SĄ
     * tu „odświeżeniem” — każde to nowy, niezależny request; jeśli stan
     * siedziałby np. tylko w odpowiedzi POST-a, ten test by to złapał.
     */
    public function test_oznaczenie_kroku_przezywa_odswiezenie_strony(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka8'), 2);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓');

        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1])
            ->assertRedirect(route('cooking.show', [$recipe->slug, 'krok' => 1]));

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Zrobione ✓');

        // Cofnięcie oznaczenia — ten sam przycisk działa w obie strony.
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 0]);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓');
    }

    public function test_oznaczenie_jednego_kroku_nie_dotyka_pozostalych(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka9'), 2);

        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1]);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]))
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓');
    }

    /** Bez JavaScriptu minutnik jest zdaniem do przeczytania, nie licznikiem. */
    public function test_minutnik_pokazuje_plaintekstowa_instrukcje_bez_js(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka10'), 1, minutnikNaPierwszym: 90);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('Ustaw sobie kuchenny minutnik na 1 minuta i 30 sekund.');
    }

    public function test_przycisk_gotuje_widoczny_na_stronie_przepisu_tylko_gdy_sa_kroki(): void
    {
        $autor = $this->user('autorka11');
        $zKrokami = $this->przepisZKrokami($autor, 1);
        $bezKrokow = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->get(route('recipes.show', $zKrokami->slug))->assertSee('Gotuję');
        $this->get(route('recipes.show', $bezKrokow->slug))->assertDontSee('Gotuję');
    }
}
