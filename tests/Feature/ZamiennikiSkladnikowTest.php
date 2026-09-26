<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Zamienniki składników wpisane przez autora (D-284, V2).
 *
 * Autor przy składniku podaje tekstem, czym go zastąpić („margaryna albo
 * olej kokosowy”); widz widzi to pod składnikiem jako „Zamiast tego: …”.
 * Sprawdzamy obie drogi zapisu (formularz bez JavaScriptu i kreator),
 * wyświetlanie, walidację po polsku przy polu, CHECK w bazie, wersję
 * przepisu i paczkę danych RODO — zamiennik to tekst człowieka, więc
 * musi być w jego kopii danych.
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie klucza `zamienniki` z
 * `CollectUserExportData::recipes()` oblewa
 * `test_zamiennik_jest_w_paczce_danych_i_w_wersji_przepisu`; usunięcie
 * `substitutes` z `PublishRecipe::syncIngredients()` oblewa testy zapisu,
 * edycji, kreatora i paczki danych.
 */
final class ZamiennikiSkladnikowTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_bez_skryptu_zapisuje_zamiennik_i_strona_go_pokazuje(): void
    {
        $autorka = $this->user('autorka_zamiennikow');

        $this->actingAs($autorka)->post(route('recipes.store'), $this->formularz([
            ['text' => '200 g masła', 'substitutes' => 'margaryna albo olej kokosowy'],
            ['text' => '2 jajka', 'substitutes' => '   '],
        ]))->assertRedirect();

        $przepis = Recipe::where('title', 'Kruche ciasto babci')->firstOrFail();
        $skladniki = $przepis->ingredients()->orderBy('position')->get();

        $this->assertSame('margaryna albo olej kokosowy', $skladniki[0]->substitutes);
        $this->assertNull($skladniki[1]->substitutes, 'Same spacje to brak zamiennika, nie pusty zamiennik.');

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="skladnik-zamiennik">Zamiast tego: margaryna albo olej kokosowy</span>', $html);
        $this->assertSame(1, substr_count($html, 'class="skladnik-zamiennik"'), 'Składnik bez zamiennika nie dostaje pustej linii „Zamiast tego:”.');

        // Tryb gotowania pokazuje to samo.
        $this->get(route('cooking.show', $przepis->slug))->assertOk()
            ->assertSee('Zamiast tego: margaryna albo olej kokosowy');
    }

    public function test_edycja_bez_skryptu_nie_gubi_zamiennika(): void
    {
        $autorka = $this->user('autorka_edycji');
        $this->actingAs($autorka)->post(route('recipes.store'), $this->formularz([
            ['text' => '200 g masła', 'substitutes' => 'margaryna'],
        ]))->assertRedirect();
        $przepis = Recipe::where('title', 'Kruche ciasto babci')->firstOrFail();

        // Formularz edycji wypełnia pole tym, co zapisane…
        $this->get(route('recipes.edit', $przepis->slug))->assertOk()
            ->assertSee('name="ingredients[0][substitutes]"', false)
            ->assertSee('value="margaryna"', false);

        // …a zapis z polem zmienia tylko to, co zmienił człowiek.
        $this->put(route('recipes.update', $przepis->slug), $this->formularz([
            ['text' => '250 g masła', 'substitutes' => 'margaryna'],
        ]))->assertRedirect();

        $this->assertSame('margaryna', $przepis->ingredients()->firstOrFail()->substitutes);
    }

    public function test_za_dlugi_zamiennik_dostaje_polski_blad_przy_polu(): void
    {
        $this->actingAs($this->user('autorka_dluga'))
            ->from(route('recipes.create.simple'))
            ->post(route('recipes.store'), $this->formularz([
                ['text' => 'masło', 'substitutes' => str_repeat('m', 301)],
            ]))
            ->assertRedirect(route('recipes.create.simple'))
            ->assertSessionHasErrors(['ingredients.0.substitutes' => 'Pole „zamiennik składnika” jest za długie. Skróć je do 300 znaków.']);

        $this->assertSame(0, Recipe::count());
    }

    public function test_kreator_wczytuje_i_zapisuje_zamiennik(): void
    {
        $autorka = $this->user('autorka_kreatora');

        Livewire::actingAs($autorka)->test('recipe-wizard')
            ->set('title', 'Placki ziemniaczane')
            ->set('ingredients.0.text', '1 kg ziemniaków')
            ->set('ingredients.0.substitutes', 'bataty')
            ->set('steps.0.instruction', 'Zetrzyj i usmaż.')
            ->call('publish')
            ->assertHasNoErrors();

        $przepis = Recipe::where('title', 'Placki ziemniaczane')->firstOrFail();
        $this->assertSame('bataty', $przepis->ingredients()->firstOrFail()->substitutes);

        // Ponowne otwarcie kreatora nie zeruje pola — inaczej pierwsza
        // edycja tytułu kasowałaby zamienniki.
        Livewire::actingAs($autorka)->test('recipe-wizard', ['recipeId' => $przepis->getKey()])
            ->assertSet('ingredients.0.substitutes', 'bataty');
    }

    public function test_kreator_mowi_przy_polu_gdy_zamiennik_za_dlugi(): void
    {
        Livewire::actingAs($this->user('autorka_kreatora_dluga'))->test('recipe-wizard')
            ->set('title', 'Placki')
            ->set('ingredients.0.text', 'ziemniaki')
            ->set('ingredients.0.substitutes', str_repeat('b', 301))
            ->set('steps.0.instruction', 'Usmaż.')
            ->call('publish')
            ->assertHasErrors(['ingredients.0.substitutes']);

        $this->assertSame(0, Recipe::where('title', 'Placki')->where('status', Recipe::STATUS_PUBLISHED)->count());
    }

    public function test_baza_nie_przyjmuje_pustego_zamiennika(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autorka_bazy')->getKey()]);

        $this->expectException(QueryException::class);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'ingredient_text' => 'masło',
            'substitutes' => '   ',
            'position' => 0,
        ]);
    }

    public function test_zamiennik_jest_w_paczce_danych_i_w_wersji_przepisu(): void
    {
        $autorka = $this->user('autorka_eksportu');

        $this->actingAs($autorka)->post(route('recipes.store'), $this->formularz([
            ['text' => '200 g masła', 'substitutes' => 'margaryna'],
            ['text' => 'sól', 'no_amount' => '1'],
        ]))->assertRedirect();

        $paczka = app(CollectUserExportData::class)->handle(
            $autorka->fresh() ?? $autorka,
            new ExportPhotoPlan($autorka),
            Carbon::parse('2026-09-26 12:00:00', 'UTC'),
        );

        $skladniki = $paczka['przepisy'][0]['skladniki'];
        $this->assertSame('margaryna', $skladniki[0]['zamienniki']);
        $this->assertArrayHasKey('zamienniki', $skladniki[1], 'Klucz ma być zawsze, także pusty — program czytający paczkę nie zgaduje.');
        $this->assertNull($skladniki[1]['zamienniki']);

        $wersja = collect($paczka['wersje_przepisow'])->last();
        $this->assertSame('margaryna', $wersja['tresc_wersji']['ingredients'][0]['substitutes']);

        $this->assertSame(1, DB::table('recipe_versions')->count(), 'Test zakłada jedną wersję po jednej publikacji.');
    }

    /**
     * @param  list<array<string, string>>  $skladniki
     * @return array<string, mixed>
     */
    private function formularz(array $skladniki): array
    {
        return [
            'action' => 'publish',
            'title' => 'Kruche ciasto babci',
            'visibility' => 'public',
            'source_type' => 'own',
            'servings' => '4',
            'ingredients' => $skladniki,
            'steps' => [['instruction' => 'Zagnieć i upiecz.']],
        ];
    }
}
