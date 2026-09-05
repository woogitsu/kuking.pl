<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Zapisz szkic" nie może opróżnić opublikowanego przepisu (audyt A07).
 *
 * CO SIĘ DZIAŁO
 * `PublishRecipe` sprawdzał kompletność tylko wtedy, gdy ktoś kliknął
 * „Opublikuj" (`if ($publish)`). Przy edycji opublikowanego przepisu
 * z `action=draft` kontrola była pomijana, `syncIngredients()` kasowało
 * i odtwarzało wiersze z pustej listy, a status zostawał `published`:
 *
 *     status: published   ingredients: 0   steps: 0   versions: 0
 *
 * Strona publiczna nadal zwracała 200 i pokazywała „Autor jeszcze nie dodał
 * składników". Bez ostrzeżenia i bez wersji do odtworzenia — snapshot powstaje
 * tylko przy publikacji, więc nie było czego przywrócić.
 *
 * DLACZEGO TO JEST GROŹNE, A NIE TYLKO NIEWYGODNE
 * Przycisk brzmi jak prywatny zapis roboczy. Człowiek, który chce odłożyć
 * poprawki „na potem", jednym kliknięciem niszczy przepis widoczny publicznie.
 * W Kuking przepis to często jedyny zapis czegoś po babci.
 *
 * WARUNEK BRZMI TERAZ „czy po zapisie przepis BĘDZIE publiczny", a nie
 * „czy ktoś kliknął Opublikuj" — bo to drugie pytanie nie ma związku z tym,
 * co zobaczą ludzie.
 */
class OpublikowanyPrzepisNieZnikaTest extends TestCase
{
    use RefreshDatabase;

    private function opublikowanyPrzepis(): Recipe
    {
        $autor = $this->user('autorka');

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => '1 kurczak'], ['text' => 'włoszczyzna']],
            'steps' => [['instruction' => 'Zalej wodą i gotuj.']],
        ])->assertRedirect();

        return Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();
    }

    public function test_zapis_szkicu_nie_kasuje_skladnikow_opublikowanego_przepisu(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $this->assertSame(2, $przepis->ingredients()->count());

        $this->actingAs($przepis->author)
            ->from(route('recipes.edit', $przepis->slug))
            ->put(route('recipes.update', $przepis->slug), [
                'action' => 'draft',
                'title' => 'Rosół babci Zofii',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [],
                'steps' => [],
            ])
            ->assertSessionHasErrors();

        $przepis->refresh();

        $this->assertSame(2, $przepis->ingredients()->count(), 'Składniki zniknęły z opublikowanego przepisu.');
        $this->assertSame(1, $przepis->steps()->count(), 'Kroki zniknęły z opublikowanego przepisu.');
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->status);
    }

    public function test_strona_przepisu_nie_zamienia_sie_w_pusta_skorupe(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $this->actingAs($przepis->author)
            ->from(route('recipes.edit', $przepis->slug))
            ->put(route('recipes.update', $przepis->slug), [
                'action' => 'draft',
                'title' => 'Rosół babci Zofii',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [],
                'steps' => [],
            ]);

        // To jest to, co zobaczyłby ktoś, kto wszedł z linku wysłanego rodzinie.
        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('1 kurczak')
            ->assertDontSee('Autor jeszcze nie dodał składników');
    }

    public function test_szkic_nadal_wolno_zapisac_niekompletny(): void
    {
        $autor = $this->user('autorka');

        // Szkic z definicji bywa niedokończony — inaczej nie byłby szkicem.
        // Naprawa nie może odebrać ludziom możliwości odłożenia pracy.
        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'draft',
            'title' => 'Zaczęty rosół',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [],
            'steps' => [],
        ])->assertRedirect();

        $przepis = Recipe::where('title', 'Zaczęty rosół')->firstOrFail();

        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
        $this->assertSame(0, $przepis->ingredients()->count());
    }

    public function test_formularz_edycji_opublikowanego_nie_pokazuje_przycisku_zapisz_szkic(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        // Przycisk, który zawsze kończy się błędem, jest gorszy niż jego brak.
        //
        // Pytamy o PRZYCISK, nie o frazę. Pierwsza wersja robiła
        // `assertDontSee('Zapisz szkic')` i padała na tekście pomocy
        // („Potrzebujesz więcej wierszy? Zapisz szkic…"), czyli na czymś
        // zupełnie innym niż to, o co pytała.
        $html = $this->actingAs($przepis->author)
            ->get(route('recipes.edit', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '~<button[^>]*name="action"[^>]*value="draft"~',
            $html,
            'Formularz edycji opublikowanego przepisu nadal ma przycisk zapisu szkicu.',
        );
    }

    public function test_formularz_nowego_przepisu_nadal_ma_zapisz_szkic(): void
    {
        $this->actingAs($this->user('autorka'))
            ->get(route('recipes.create.simple'))
            ->assertOk()
            ->assertSee('Zapisz szkic');
    }

    public function test_niedokonczony_szkic_wolno_dalej_zapisywac_jako_szkic(): void
    {
        $autor = $this->user('autorka');

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'draft',
            'title' => 'Roboczy',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'mąka']],
            'steps' => [],
        ])->assertRedirect();

        $przepis = Recipe::where('title', 'Roboczy')->firstOrFail();

        // Niepublikowany przepis zostaje w pełni edytowalny, także „w dół".
        $this->actingAs($autor)->put(route('recipes.update', $przepis->slug), [
            'action' => 'draft',
            'title' => 'Roboczy',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [],
            'steps' => [],
        ])->assertRedirect();

        $this->assertSame(0, $przepis->refresh()->ingredients()->count());
        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
    }
}
