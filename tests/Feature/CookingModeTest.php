<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
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
    use WycinaObudoweEkranu;

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

        $odpowiedz = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))->assertOk();

        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1): belka dla
        // gościa ma własny przycisk „Załóż konto" na KAŻDYM ekranie, więc
        // asercja na całej odpowiedzi przechodziła także po skasowaniu zachęty
        // z ostatniego kroku — czyli dokładnie tego, czego miała pilnować.
        $this->assertStringContainsString(
            'Załóż konto',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );

        // Asercja „czegoś nie ma" zostaje na CAŁYM dokumencie — tu szersze
        // spojrzenie jest bezpieczniejsze, nie słabsze.
        $odpowiedz->assertDontSee('Ugotowałem');
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
            ->assertSee('Ustaw sobie kuchenny minutnik na 1 minutę i 30 sekund.')
            ->assertSee('data-timer-etykieta="1 minutę i 30 sekund"', false)
            ->assertSee('data-timer-sekundy="90"', false);
    }

    public function test_przycisk_gotuje_widoczny_na_stronie_przepisu_tylko_gdy_sa_kroki(): void
    {
        $autor = $this->user('autorka11');
        $zKrokami = $this->przepisZKrokami($autor, 1);
        $bezKrokow = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->get(route('recipes.show', $zKrokami->slug))->assertSee('Gotuję');
        $this->get(route('recipes.show', $bezKrokow->slug))->assertDontSee('Gotuję');
    }

    /**
     * Regresja issue #740: nawigacja krokami i oznaczenie kroku jako
     * zrobiony przeładowują stronę, co zeruje cały stan JavaScriptu
     * (patrz `resources/js/app.js` — `performance.now()` liczy od nowa od
     * każdego przeładowania). Skrypt odtwarza aktywny minutnik z
     * `sessionStorage`, ale żeby w ogóle wiedzieć, KTÓREGO kroku KTÓREGO
     * przepisu dotyczy zapis, znacznik musi nieść obie te wartości — sama
     * arytmetyka zapisu/odczytu jest jednostkowo przetestowana w
     * `resources/js/minutnik-krok.test.mjs`, tu pilnujemy tylko, że
     * znacznik faktycznie je niesie.
     */
    public function test_minutnik_niesie_tozsamosc_przepisu_i_kroku_dla_js(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka12'), 2, minutnikNaPierwszym: 90);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('data-timer-recipe="'.$recipe->slug.'"', false)
            ->assertSee('data-timer-krok="1"', false);
    }

    /**
     * Regresja issue #1301: minutnik uruchomiony w kroku 1 nie alarmował
     * po przejściu do kroku 2, bo na stronie kroku 2 nie było nic, co by go
     * odliczało. Pas alarmów innych kroków musi stać na KAŻDYM kroku — także
     * bez własnego minutnika — i nieść przepis oraz widoczny krok, żeby
     * skrypt wiedział, których zapisów pilnować. Samo zachowanie (alarm,
     * „Wyłącz alarm”, brak podwójnego alarmu) sprawdza przeglądarka
     * w `scripts/minutnik-regresja.mjs`.
     */
    public function test_krok_bez_minutnika_ma_pas_alarmow_innych_krokow(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka30'), 2, minutnikNaPierwszym: 90);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]))
            ->assertDontSee('data-timer-krok', false)
            ->assertSee('class="cook-alarmy stack"', false)
            ->assertSee('data-alarmy-recipe="'.$recipe->slug.'"', false)
            ->assertSee('data-alarmy-krok="2"', false)
            ->assertSee('data-alarmy-adres="'.route('cooking.show', $recipe->slug).'"', false);
    }

    /**
     * Regresja issue #755: minutnik ma dać się świadomie anulować, nie
     * tylko doczekać do końca albo opuścić tryb gotowania. Przycisk stoi
     * w znaczniku niezależnie od JS-u (ulepszenie odsłania go dopiero
     * skrypt — patrz `resources/js/app.js`), więc test na treści strony
     * łapie zniknięcie samego przycisku, nie stanu `hidden`, którego klient
     * testowy Laravela i tak nie interpretuje jak przeglądarka.
     */
    public function test_minutnik_ma_przycisk_anulowania(): void
    {
        $recipe = $this->przepisZKrokami($this->user('autorka13'), 1, minutnikNaPierwszym: 60);

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertSee('cook-timer-anuluj', false)
            ->assertSee('Anuluj minutnik');
    }

    /**
     * Regresja issue #764: tryb gotowania pokazywał składniki jedną płaską
     * listą — bez grup autora (`App\Domain\Recipes\GrupySkladnikow`, ten
     * sam mechanizm co na stronie przepisu) i bez „do smaku” dla składników
     * oznaczonych `no_amount`. Efekt: przepis z grupami „Ciasto”/„Farsz”
     * gubił tę strukturę wyłącznie w trybie gotowania, a „sól” zaznaczona
     * jako „bez ilości” wyglądała jak składnik bez żadnej informacji
     * o ilości, zamiast jak świadome „do smaku”.
     */
    public function test_skladniki_pokazuja_grupy_i_do_smaku(): void
    {
        $autor = $this->user('autorka14');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'group_name' => 'Ciasto',
            'ingredient_text' => 'mąka',
            'position' => 0,
        ]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'group_name' => 'Farsz',
            'ingredient_text' => 'sól',
            'no_amount' => true,
            'position' => 1,
        ]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok.']);

        $odpowiedz = $this->get(route('cooking.show', $recipe->slug))->assertOk();

        $odpowiedz->assertSeeInOrder(['Ciasto', 'mąka', 'Farsz', 'sól', 'do smaku']);
    }
}
