<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Liczba porcji na stronie przepisu (audyt A28).
 *
 * Kolumna `servings` to `decimal(6,2)`, formularz dopuszcza `step=0.5`
 * i `min=0.5`, a widok robił `(int) $recipe->servings`. Efekt:
 *
 *     w bazie 0.5  → na stronie „0 porcji"
 *     w bazie 1.5  → na stronie „1 porcji"
 *
 * To nie jest literówka w widoku, tylko przepis mówiący nieprawdę o samym
 * sobie — a że ta sama wartość szła do JSON-LD jako `recipeYield`, nieprawda
 * trafiała też do Google.
 *
 * Drugą połową naprawy jest polszczyzna. `AGENTS.md` §11 i `docs/UX_50_PLUS.md`
 * wymagają poprawnego języka, a „1 porcji" i „2 porcji" to nie jest polski.
 * Liczebnik po polsku rządzi rzeczownikiem:
 *
 *     1        → porcja
 *     2, 3, 4  → porcje       (ale 12, 13, 14 → porcji)
 *     5 i dalej→ porcji
 *     ułamek   → porcji       („1,5 porcji")
 *
 * Separator dziesiętny po polsku to przecinek, nie kropka — „1.5 porcji"
 * wygląda jak niedokończone tłumaczenie.
 */
class OdmianaPorcjiTest extends TestCase
{
    use RefreshDatabase;

    private function przepisNaPorcje(?float $porcje): Recipe
    {
        return Recipe::factory()->make(['servings' => $porcje]);
    }

    public static function porcjeProvider(): array
    {
        return [
            'pół porcji' => [0.5, '0,5 porcji'],
            'jedna' => [1.0, '1 porcja'],
            'półtorej' => [1.5, '1,5 porcji'],
            'dwie' => [2.0, '2 porcje'],
            'dwie i pół' => [2.5, '2,5 porcji'],
            'trzy' => [3.0, '3 porcje'],
            'cztery' => [4.0, '4 porcje'],
            'pięć' => [5.0, '5 porcji'],
            'jedenaście' => [11.0, '11 porcji'],
            // Pułapka polskiego liczebnika: 12–14 idą jak 5, mimo końcówki 2–4.
            'dwanaście' => [12.0, '12 porcji'],
            'trzynaście' => [13.0, '13 porcji'],
            'czternaście' => [14.0, '14 porcji'],
            'dwadzieścia dwie' => [22.0, '22 porcje'],
            'dwadzieścia pięć' => [25.0, '25 porcji'],
            'sto dwie' => [102.0, '102 porcje'],
            'sto dwanaście' => [112.0, '112 porcji'],
            'ćwierć części' => [0.75, '0,75 porcji'],
        ];
    }

    #[DataProvider('porcjeProvider')]
    public function test_odmiana_porcji_po_polsku(float $porcje, string $oczekiwane): void
    {
        $this->assertSame($oczekiwane, $this->przepisNaPorcje($porcje)->servingsLabel());
    }

    public function test_brak_liczby_porcji_nie_wymyśla_zera(): void
    {
        // Przepis bez podanej liczby porcji nie ma pokazywać „0 porcji" ani
        // pustego znaczka — po prostu nie ma czego powiedzieć.
        $this->assertNull($this->przepisNaPorcje(null)->servingsLabel());
    }

    public function test_polowka_porcji_nie_znika_ze_strony_przepisu(): void
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'servings' => 0.5,
            'slug' => 'pol-porcji-deseru',
        ]);

        $this->get(route('recipes.show', $recipe->slug))
            ->assertOk()
            ->assertSee('0,5 porcji', false)
            ->assertDontSee('0 porcji', false);
    }

    public function test_poltora_porcji_nie_zaokragla_sie_w_dol(): void
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'servings' => 1.5,
            'slug' => 'poltorej-porcji-zupy',
        ]);

        $this->get(route('recipes.show', $recipe->slug))
            ->assertOk()
            ->assertSee('1,5 porcji', false)
            ->assertDontSee('1 porcji', false);
    }

    public function test_json_ld_podaje_google_prawdziwa_liczbe_porcji(): void
    {
        // `Recipe` w JSON-LD tylko przy gotowym zdjęciu (#1005).
        $recipe = Recipe::factory()->zeZdjeciem()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'servings' => 1.5,
            'slug' => 'poltorej-porcji-do-google',
        ]);

        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();

        // Dekodujemy blok JSON-LD zamiast szukać podciągu: koder celowo
        // zamienia cudzysłowy na sekwencje " (audyt A01), więc
        // porównywanie tekstu sprawdzałoby zapis, a nie wartość.
        // `[^>]*` po typie: znacznik nosi jeszcze `nonce` z polityki CSP
        // (issue #12), a wzorzec przybity do dokładnej postaci znacznika
        // psuje się przy każdym dołożonym atrybucie.
        $this->assertSame(1, preg_match(
            '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $dopasowanie,
        ));

        $dane = json_decode($dopasowanie[1], true, 512, JSON_THROW_ON_ERROR);

        // recipeYield idzie do wyników wyszukiwania. „1 porcji" to jednocześnie
        // zła liczba i zły polski — w miejscu, którego autor nie widzi
        // i nie poprawi.
        $this->assertSame('1,5 porcji', $dane['recipeYield']);
    }

    public function test_jedna_porcja_jest_w_liczbie_pojedynczej(): void
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'servings' => 1,
            'slug' => 'jedna-porcja-omletu',
        ]);

        $this->get(route('recipes.show', $recipe->slug))
            ->assertOk()
            ->assertSee('1 porcja', false);
    }
}
