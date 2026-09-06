<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran przepisu według UI kitu v2, etap C (ekrany 02 i 06).
 *
 * CO TEN TEST PILNUJE, A CZEGO NIE
 * Nie sprawdza wyglądu — od tego są zrzuty ekranu i axe. Sprawdza trzy
 * rzeczy, które przy przestylowywaniu układu najłatwiej zgubić i których
 * nikt nie zauważy od razu:
 *
 *  1. KOLEJNOŚĆ W DRZEWIE. Na telefonie kolumny nie ma — jest jeden ciąg,
 *     i to kolejność w kodzie decyduje, co człowiek czyta najpierw. Panel
 *     z „Ugotowałem" MUSI stać przed składnikami: do etapu C główna akcja
 *     produktu leżała na samym dole strony, pod krokami, czyli widział ją
 *     tylko ten, kto przewinął cały przepis.
 *
 *  2. NIC NIE ZGINĘŁO. Przeniesienie akcji do panelu to była przeprowadzka
 *     całej sekcji; łatwo przy niej zostawić na stronie połowę przycisków.
 *
 *  3. D-017. Kit pokazuje składniki w dwóch kolumnach („Mąka | 500 g")
 *     i kroki z tytułami („1. Przygotuj ciasto"). U nas składnik to jedno
 *     pole wolnego tekstu, a krok jednym ciągiem zdań — więc obie te rzeczy
 *     trzeba by ZGADYWAĆ. Test pilnuje, że tekst autora idzie na ekran
 *     w całości i bez dopisków.
 */
class EkranPrzepisuWedlugKituTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        $autor = $this->user('autor');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Rosół babci Zofii',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'servings' => 4,
            'prep_minutes' => 20,
            'cook_minutes' => 100,
            'difficulty' => 'easy',
        ]);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'ingredient_text' => 'pół kurczaka, najlepiej zagrodowego',
        ]);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Zalej mięso zimną wodą i gotuj bez pokrywki.',
        ]);

        return $przepis;
    }

    public function test_glowna_akcja_stoi_przed_skladnikami(): void
    {
        $przepis = $this->przepis();

        $html = $this->actingAs($this->user('gosc'))
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $ugotowalem = strpos($html, '>Ugotowałem<');
        $skladniki = strpos($html, '>Składniki<');

        $this->assertNotFalse($ugotowalem, 'Na ekranie przepisu nie ma przycisku „Ugotowałem".');
        $this->assertNotFalse($skladniki);
        $this->assertLessThan(
            $skladniki,
            $ugotowalem,
            'Główna akcja wróciła pod składniki — czyli tam, gdzie widzi ją tylko ten, kto przewinie cały przepis.',
        );
    }

    public function test_przeprowadzka_do_panelu_nic_nie_zgubila(): void
    {
        $przepis = $this->przepis();

        $odpowiedz = $this->actingAs($this->user('gosc'))
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk();

        foreach ([
            'Ugotowałem',
            'Zapisuję',
            'Gotuję — pokaż kroki na cały ekran',
            'Obserwuj',
            'Składniki',
            'Przygotowanie',
            'Komu wyszło',
        ] as $czego_ma_nie_zabraknac) {
            $odpowiedz->assertSee($czego_ma_nie_zabraknac, escape: false);
        }

        // Kafle liczb z kitu — tylko te, które autor naprawdę podał.
        $odpowiedz->assertSee('Około 120 min', escape: false)
            ->assertSee('4 porcje', escape: false)
            ->assertSee('Poziom', escape: false);
    }

    public function test_kafel_nie_powstaje_dla_liczby_ktorej_autor_nie_podal(): void
    {
        // Kit rysuje zawsze trzy kafle. Kafel „—" nie jest informacją:
        // mówi „nie wiemy", zajmując tyle miejsca, co odpowiedź.
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'servings' => null,
            'prep_minutes' => null,
            'cook_minutes' => null,
            'difficulty' => null,
        ]);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Poziom', escape: false)
            ->assertDontSee('>Czas<', escape: false);
    }

    public function test_skladnik_i_krok_ida_na_ekran_w_calosci_d017(): void
    {
        $przepis = $this->przepis();

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            // Bez kolumny ilości: cały tekst autora, jednym kawałkiem.
            ->assertSee('pół kurczaka, najlepiej zagrodowego', escape: false)
            // Bez wymyślonego tytułu kroku — sam numer i zdanie autora.
            ->assertSee('Zalej mięso zimną wodą i gotuj bez pokrywki.', escape: false);
    }

    public function test_ekran_przepisu_jest_szeroki_a_tekst_ciagly_nie(): void
    {
        // Sufit czytelności nie znika razem z szerszą kolumną — przenosi się
        // na pojedyncze bloki. Bez `kolumna-czytania` wstęp i komentarze
        // rozciągają się na ~95 znaków w wierszu.
        $przepis = $this->przepis();
        $przepis->update(['summary' => 'Rosół, który u nas stoi na kuchni od niedzieli rano.']);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString('app-main-szeroka', $html);
        $this->assertStringContainsString('kolumna-czytania', $html);
    }
}
