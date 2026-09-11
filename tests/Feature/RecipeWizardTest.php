<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kreator przepisu (issue #1) i wiersze składników/kroków (issue #13).
 *
 * Najważniejszy test w tym pliku nie sprawdza, czy coś ładnie wygląda, tylko
 * czy PRZERWANIE KREATORA NIE KASUJE DANYCH (docs/ROADMAP.md, punkt 5).
 */
class RecipeWizardTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    public function test_krok_pierwszy_zapisuje_szkic_i_dane_wracaja_po_powrocie(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Rosół babci Zofii')
            ->set('summary', 'Na niedzielę, zawsze z makaronem.')
            ->set('servings', '6')
            ->call('next')
            ->assertSet('step', 2)
            ->assertSet('saveMessage', 'Szkic zapisany.');

        $recipe = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);

        // Zamknięcie karty i powrót = nowy komponent, ten sam szkic.
        Livewire::actingAs($basia)
            ->test(self::COMPONENT, ['recipeId' => $recipe->getKey()])
            ->assertSet('title', 'Rosół babci Zofii')
            ->assertSet('summary', 'Na niedzielę, zawsze z makaronem.')
            ->assertSet('servings', '6');
    }

    public function test_autosave_odpala_sie_po_zmianie_pola_bez_klikania_dalej(): void
    {
        $basia = $this->user('basia');

        // wire:model.live.debounce.3000ms → hook updated() → zapis szkicu.
        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Żurek na zakwasie')
            ->assertSet('saveMessage', 'Szkic zapisany.');

        $this->assertDatabaseHas('recipes', [
            'title' => 'Żurek na zakwasie',
            'status' => Recipe::STATUS_DRAFT,
        ]);
    }

    public function test_bez_nazwy_kreator_mowi_czego_brakuje_i_nie_tworzy_pustego_przepisu(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('ingredients.0.text', 'szklanka mąki')
            ->assertSet('saveMessage', 'Szkic zapisze się, kiedy podasz nazwę przepisu.');

        $this->assertSame(0, Recipe::count());
    }

    public function test_dalej_z_pustej_nazwy_nie_przepuszcza_i_pokazuje_polski_komunikat(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', '')
            ->call('next')
            ->assertSet('step', 1)
            ->assertHasErrors('title')
            ->assertSee('Podaj nazwę przepisu', false);
    }

    public function test_kreator_publikuje_przepis_bez_ani_jednego_skladnika(): void
    {
        $basia = $this->user('basia');

        // ZGODA WŁAŚCICIELA z 11.09.2026 (issue #364): „przepis wolno
        // opublikować bez ani jednego składnika". Do tego dnia kreator
        // odsyłał tu na krok drugi z błędem `ingredients` — i to była
        // ostatnia bramka, która kazała rozstrzygnąć strukturę przepisu,
        // zanim wolno było cokolwiek opublikować.
        $component = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Przepis bez składników')
            ->set('summary', 'Historia, której nie wolno zgubić.')
            ->set('steps.0.instruction', 'Wymieszać wszystko.')
            ->set('step', 4)
            ->call('publish');

        $component->assertHasNoErrors();

        $recipe = Recipe::where('title', 'Przepis bez składników')->firstOrFail();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertCount(0, $recipe->ingredients);
        $this->assertSame('Historia, której nie wolno zgubić.', $recipe->summary);
        $this->assertSame('Wymieszać wszystko.', $recipe->steps->first()->instruction);
    }

    public function test_publikacja_bez_krokow_nie_kasuje_wpisanych_danych(): void
    {
        $basia = $this->user('basia');

        $component = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Przepis bez przygotowania')
            ->set('ingredients.0.text', 'kurczak')
            ->set('step', 4)
            ->call('publish');

        $component->assertHasErrors('steps')
            ->assertSet('step', 3)
            ->assertSet('ingredients.0.text', 'kurczak');

        $this->assertStringContainsString(
            'Opisz przynajmniej jeden krok',
            (string) $component->errors()->first('steps'),
        );

        $recipe = Recipe::where('title', 'Przepis bez przygotowania')->firstOrFail();
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('kurczak', $recipe->ingredients->first()->ingredient_text);
    }

    public function test_pusty_wiersz_nie_zapisuje_sie_jako_pusty_skladnik(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Jajecznica')
            ->set('ingredients.0.text', '3 jajka')
            ->set('ingredients.1.text', '   ')
            ->set('ingredients.2.text', 'masło')
            ->set('steps.0.instruction', 'Rozbić jajka.')
            ->set('steps.1.instruction', '')
            ->call('publish');

        $recipe = Recipe::where('title', 'Jajecznica')->firstOrFail();

        $this->assertSame(['3 jajka', 'masło'], $recipe->ingredients->pluck('ingredient_text')->all());
        $this->assertSame([0, 1], $recipe->ingredients->pluck('position')->all());
        $this->assertSame(['Rozbić jajka.'], $recipe->steps->pluck('instruction')->all());
    }

    public function test_usuniecie_wiersza_ze_srodka_nie_psuje_numeracji_position(): void
    {
        $basia = $this->user('basia');

        $component = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Naleśniki')
            ->set('ingredients.0.text', 'mąka')
            ->set('ingredients.1.text', 'mleko')
            ->set('ingredients.2.text', 'jajka')
            ->call('addIngredient')
            ->set('ingredients.3.text', 'szczypta soli')
            ->call('removeIngredient', 1);

        $component->assertSet('ingredients.0.text', 'mąka')
            ->assertSet('ingredients.1.text', 'jajka')
            ->assertSet('ingredients.2.text', 'szczypta soli');

        $recipe = Recipe::where('title', 'Naleśniki')->firstOrFail();

        // UNIQUE (recipe_id, position) w bazie nie wybaczyłby dziury
        // ani duplikatu — pozycje muszą być ciągłe.
        $this->assertSame(['mąka', 'jajka', 'szczypta soli'], $recipe->ingredients->pluck('ingredient_text')->all());
        $this->assertSame([0, 1, 2], $recipe->ingredients->pluck('position')->all());
    }

    public function test_usuniecie_kroku_ze_srodka_nie_psuje_numeracji_position(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Zupa pomidorowa')
            ->set('steps.0.instruction', 'Ugotuj wywar.')
            ->set('steps.1.instruction', 'Dodaj przecier.')
            ->set('steps.2.instruction', 'Zabiel śmietaną.')
            ->call('removeStep', 1);

        $recipe = Recipe::where('title', 'Zupa pomidorowa')->firstOrFail();

        $this->assertSame(['Ugotuj wywar.', 'Zabiel śmietaną.'], $recipe->steps->pluck('instruction')->all());
        $this->assertSame([0, 1], $recipe->steps->pluck('position')->all());
    }

    public function test_przyciski_w_gore_i_w_dol_zmieniaja_kolejnosc(): void
    {
        $basia = $this->user('basia');

        $component = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Sernik')
            ->set('ingredients.0.text', 'twaróg')
            ->set('ingredients.1.text', 'cukier')
            ->set('ingredients.2.text', 'jajka')
            ->call('moveIngredientDown', 0)
            ->assertSet('ingredients.0.text', 'cukier')
            ->assertSet('ingredients.1.text', 'twaróg')
            ->call('moveIngredientUp', 2)
            ->assertSet('ingredients.1.text', 'jajka')
            ->assertSet('ingredients.2.text', 'twaróg');

        // Kolejność z ekranu ma trafić do bazy w tej samej postaci.
        $recipe = Recipe::where('title', 'Sernik')->firstOrFail();
        $this->assertSame(['cukier', 'jajka', 'twaróg'], $recipe->ingredients->pluck('ingredient_text')->all());

        // Skrajne przyciski nie mogą wyrzucić wiersza poza listę.
        $component->call('moveIngredientUp', 0)
            ->assertSet('ingredients.0.text', 'cukier')
            ->call('moveIngredientDown', 2)
            ->assertSet('ingredients.2.text', 'twaróg');
    }

    public function test_przepis_z_dwudziestoma_skladnikami_zapisuje_sie_i_zachowuje_kolejnosc(): void
    {
        $basia = $this->user('basia');

        $component = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Bigos na dwadzieścia rzeczy')
            ->set('steps.0.instruction', 'Wszystko razem, trzy dni.');

        $oczekiwane = [];

        for ($i = 0; $i < 20; $i++) {
            $tekst = 'składnik numer '.($i + 1);
            $oczekiwane[] = $tekst;

            if ($i >= 3) {
                $component->call('addIngredient');
            }

            $component->set("ingredients.{$i}.text", $tekst);
        }

        $component->call('publish');

        $recipe = Recipe::where('title', 'Bigos na dwadzieścia rzeczy')->firstOrFail();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertCount(20, $recipe->ingredients);
        $this->assertSame($oczekiwane, $recipe->ingredients->pluck('ingredient_text')->all());
        $this->assertSame(range(0, 19), $recipe->ingredients->pluck('position')->all());
    }

    public function test_grupy_skladnikow_zapisuja_sie_z_pola_group_name(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Sernik z kruszonką')
            ->set('ingredients.0.text', 'mąka')
            ->set('ingredients.0.group_name', 'Ciasto')
            ->set('ingredients.1.text', 'twaróg')
            ->set('ingredients.1.group_name', 'Nadzienie')
            ->set('steps.0.instruction', 'Upiec.')
            ->call('publish');

        $recipe = Recipe::where('title', 'Sernik z kruszonką')->firstOrFail();

        $this->assertSame(['Ciasto', 'Nadzienie'], $recipe->ingredients->pluck('group_name')->all());
    }

    public function test_kreator_publikuje_przepis_i_przenosi_na_jego_strone(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Rosół')
            ->set('ingredients.0.text', 'kura')
            ->set('steps.0.instruction', 'Gotować trzy godziny.')
            ->call('publish')
            ->assertRedirect(route('recipes.show', 'rosol'));

        $recipe = Recipe::where('slug', 'rosol')->firstOrFail();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertSame(1, $recipe->versions()->count());
    }

    public function test_przerwanie_kreatora_w_polowie_zostawia_szkic_z_wszystkim_co_wpisane(): void
    {
        $basia = $this->user('basia');

        // Krok 1 → krok 2 → część składników → zamknięcie karty.
        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Pierogi ruskie')
            ->set('source_person', 'po babci Zofii')
            ->call('next')
            ->set('ingredients.0.text', 'mąka')
            ->set('ingredients.1.text', 'twaróg');

        $recipe = Recipe::where('title', 'Pierogi ruskie')->firstOrFail();

        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('po babci Zofii', $recipe->source_person);
        $this->assertSame(['mąka', 'twaróg'], $recipe->ingredients->pluck('ingredient_text')->all());
    }

    public function test_kreator_nie_otwiera_cudzego_szkicu(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        $szkic = Recipe::factory()->draft()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($obcy)
            ->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertForbidden();

        $this->actingAs($basia)
            ->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertOk()
            ->assertSee($szkic->title, false);
    }

    public function test_strona_kreatora_zawsze_prowadzi_do_formularza_bez_javascriptu(): void
    {
        $basia = $this->user('basia');

        // Od #364 kreator NIE JEST ekranem dodawania — jest ekranem
        // „Dopisz szczegóły" i wchodzi się do niego z istniejącego przepisu.
        // Reguła, której ten test pilnuje, się nie zmieniła: droga bez
        // JavaScriptu ma być widoczna ZAWSZE, nie tylko w `<noscript>`.
        $przepis = Recipe::factory()->for($basia, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($basia)
            ->get(route('recipes.details', $przepis->slug))
            ->assertOk()
            ->assertSee('Krok 1 z 3', false)
            ->assertSee(route('recipes.edit', $przepis->slug), false)
            ->assertSee('<noscript>', false);
    }

    public function test_fallback_bez_javascriptu_dalej_publikuje_przepis(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->get(route('recipes.create.simple'))->assertOk();

        $this->actingAs($basia)
            ->post(route('recipes.store'), [
                'title' => 'Kompot z jabłek',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [
                    ['text' => 'jabłka'],
                    ['text' => ''],
                    ['text' => 'goździki'],
                ],
                'steps' => [
                    ['instruction' => 'Zagotować.'],
                    ['instruction' => ''],
                ],
                'action' => 'publish',
            ])
            ->assertRedirect(route('recipes.show', 'kompot-z-jablek'));

        $recipe = Recipe::where('slug', 'kompot-z-jablek')->firstOrFail();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertSame(['jabłka', 'goździki'], $recipe->ingredients->pluck('ingredient_text')->all());
        $this->assertSame([0, 1], $recipe->ingredients->pluck('position')->all());
    }

    /**
     * Regresja: edycja przepisu gubiła zdjęcie kartki z zeszytu.
     *
     * RecipeController::update nie przekazywał source_scan_media_id, a
     * PublishRecipe zapisuje dokładnie to, co dostanie — więc pierwsza
     * zmiana tytułu kasowała zeskanowaną kartkę babci. Bezpowrotnie.
     */
    public function test_edycja_przepisu_nie_kasuje_zdjecia_kartki_z_zeszytu(): void
    {
        $basia = $this->user('basia');
        $kartka = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $recipe = Recipe::factory()->draft()->create([
            'author_id' => $basia->getKey(),
            'source_scan_media_id' => $kartka->getKey(),
        ]);

        $this->actingAs($basia)
            ->put(route('recipes.update', $recipe->slug), [
                'title' => 'Ciasto babci, poprawiona nazwa',
                'visibility' => 'public',
                'source_type' => 'family',
                'ingredients' => [['text' => 'mąka']],
                'steps' => [['instruction' => 'Upiec.']],
                'action' => 'draft',
            ])
            ->assertRedirect();

        $this->assertSame($kartka->getKey(), $recipe->refresh()->source_scan_media_id);
    }

    public function test_niedokonczony_szkic_widac_na_stronie_dodawania(): void
    {
        $basia = $this->user('basia');
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Niedokończony bigos',
        ]);

        $this->actingAs($basia)
            ->get(route('add'))
            ->assertOk()
            ->assertSee('Niedokończony bigos', false)
            ->assertSee(route('recipes.create', ['szkic' => $szkic->getKey()]), false);
    }
}
