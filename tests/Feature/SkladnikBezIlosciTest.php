<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Sól do smaku" nie skaluje się razy trzy (issue #44).
 *
 * PO CO TO JEST, SKORO SKALOWANIA PORCJI JESZCZE NIE MA
 * Bo teraz kosztuje jedną kolumnę, a później kosztuje migrację danych
 * i ZGADYWANIE. Kiedy w tabeli będą przepisy prawdziwych ludzi, nikt nie
 * odróżni „mleko — ile weźmie" od „mleko 200 ml" inaczej niż heurystyką
 * po tekście — a heurystyka pomyli się na czyimś przepisie po babci i nie
 * będzie komu tego zauważyć.
 *
 * Przepis przeliczony razy trzy poprosiłby bez tej flagi o trzy szczypty
 * soli (śmieszne) i o trzy razy „ile weźmie" (bez sensu). Dla naszego
 * odbiorcy to nie jest drobiazg kosmetyczny: to jest moment, w którym
 * przepis przestaje wyglądać na napisany przez człowieka.
 */
class SkladnikBezIlosciTest extends TestCase
{
    use RefreshDatabase;

    public function test_skladnik_bez_ilosci_nie_ma_ani_ilosci_ani_jednostki(): void
    {
        $autor = $this->user('basia');

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => '1 kurczak'],
                ['text' => 'sól', 'no_amount' => '1'],
            ],
            'steps' => [['instruction' => 'Zalej wodą i gotuj.']],
        ])->assertRedirect();

        $sol = RecipeIngredient::where('ingredient_text', 'sól')->firstOrFail();

        $this->assertTrue($sol->no_amount);
        $this->assertNull($sol->quantity);
        $this->assertNull($sol->unit_id);

        // Domyślnie WYŁĄCZONE. Każdy przepis zapisany wcześniej i każdy
        // składnik, przy którym nikt nic nie zaznaczył, zachowuje się
        // dokładnie tak jak dotąd.
        $this->assertFalse(RecipeIngredient::where('ingredient_text', '1 kurczak')->firstOrFail()->no_amount);
    }

    public function test_baza_odrzuca_skladnik_ktory_jednoczesnie_ma_i_nie_ma_ilosci(): void
    {
        // Bez CHECK-a dałoby się zapisać wiersz mówiący naraz „nie mam ilości"
        // i „mam 200 ml". Wtedy pytanie „czy to skalować" nie ma poprawnej
        // odpowiedzi — a baza jest ostatnim miejscem, które może tego
        // pilnować, gdy dane wchodzą inną drogą niż formularz (import,
        // seeder, konsola).
        $przepis = Recipe::factory()->create(['author_id' => $this->user('basia')->getKey()]);
        $jednostka = Unit::query()->first() ?? Unit::create(['code' => 'ml', 'name' => 'mililitr']);

        $this->expectException(QueryException::class);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'ingredient_text' => 'mleko',
            'quantity' => 200,
            'unit_id' => $jednostka->getKey(),
            'no_amount' => true,
            'position' => 0,
        ]);
    }

    public function test_zaznaczenie_bez_ilosci_kasuje_wpisana_ilosc_zamiast_wywalic_publikacje(): void
    {
        // CHECK w bazie znaczy, że wiersz „bez ilości" z wpisaną ilością
        // nie przeszedłby w ogóle — czyli błąd 500 na publikacji i utrata
        // całej pracy autora. Skoro człowiek powiedział „do smaku", ilość
        // jest tym, co odpada: to jedyna interpretacja, która nie każe mu
        // niczego poprawiać.
        $przepis = app(PublishRecipe::class)->handle(
            author: $this->user('basia'),
            attributes: [
                'title' => 'Rosół z solą do smaku',
                'visibility' => 'public',
                'source_type' => 'own',
            ],
            ingredients: [
                ['text' => 'sól', 'no_amount' => true, 'quantity' => 200.0],
            ],
            steps: [['instruction' => 'Gotuj.']],
            publish: true,
        );

        $sol = $przepis->ingredients()->firstOrFail();

        $this->assertTrue($sol->no_amount);
        $this->assertNull($sol->quantity, 'Ilość przetrwała mimo „bez ilości” — CHECK w bazie wywali publikację.');
    }

    public function test_przepis_zachowuje_tekst_autora_bez_dopisywania_sposobu_dozowania(): void
    {
        $autor = $this->user('basia');

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => 'pieprz', 'no_amount' => '1'],
                ['text' => 'sól do smaku', 'no_amount' => '1'],
                ['text' => 'mleko ile weźmie', 'no_amount' => '1', 'group_name' => 'Ciasto'],
                ['text' => 'olej do smażenia', 'no_amount' => '1', 'group_name' => 'Ciasto', 'note' => 'na patelnię'],
                ['text' => 'szczypta soli', 'no_amount' => '1'],
                ['text' => '200 ml wody', 'no_amount' => '0'],
            ],
            'steps' => [['instruction' => 'Gotuj.']],
        ])->assertRedirect();

        $przepis = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//ul[@class="ingredient-list"]/li');
        $texts = [];
        foreach ($rows as $row) {
            $texts[] = preg_replace('/\s+/u', ' ', trim($row->textContent));
        }
        $this->assertSame([
            'pieprz', 'sól do smaku', 'szczypta soli', '200 ml wody',
            'mleko ile weźmie', 'olej do smażenia — na patelnię',
        ], $texts);
        $this->assertSame('Ciasto', trim($xpath->query('//h3[@class="naglowek-grupy"]')->item(0)->textContent));
    }

    public function test_formularz_jednostronicowy_ma_te_opcje_bez_javascriptu(): void
    {
        // Kreator w trzech krokach chodzi na Livewire. Formularz na jednej
        // stronie jest drogą dla osób, u których skrypt się nie dociągnął —
        // i tam ta opcja też musi być, bo inaczej „bez ilości" byłoby
        // funkcją dla lepiej wyposażonych (AGENTS.md §5).
        $html = (string) $this->actingAs($this->user('basia'))
            ->get(route('recipes.create.simple'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="ingredients[0][no_amount]"', $html);
        $this->assertStringContainsString('Bez ilości', $html);
    }
}
