<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checklista przygotowania składników w trybie „Gotuję” (issue #2069).
 *
 * CO TU JEST, A CZEGO NIE MA
 * Odhaczenie składnika to PAMIĘĆ TEJ KARTY (`sessionStorage`), nie dane
 * konta — tak rozstrzyga kontrakt z komentarza do issue i tak samo działa
 * już zapamiętany przełącznik „Nie usypiaj ekranu” (#1302). Serwer niczego
 * więc nie zapisuje; ten test pilnuje KSZTAŁTU znacznika, z którego czyta
 * skrypt `resources/js/skladniki-gotowania.js`:
 *  - każdy składnik ma własne pole, klucz to ID składnika, nie pozycja,
 *  - bez skryptu nie widać ani jednej kontrolki (D-053: żadnego martwego
 *    przycisku) — lista wygląda jak dotąd,
 *  - „Wyczyść zaznaczenie składników” nie jest formularzem i nie wskazuje
 *    na reset kroków.
 * Samo zachowanie (odhaczenie, powrót po zmianie kroku, reset, klawiatura,
 * 320 px, cele dotyku) sprawdza przeglądarka:
 * `scripts/przegladarka/skladniki-gotowania.test.mjs` — na znaczniku w tym
 * samym kształcie, który pilnuje ten plik.
 */
class ChecklistaSkladnikowGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Przepis z dwiema grupami i tym samym składnikiem „sól” w obu —
     * przypadek, w którym klucz po nazwie albo po pozycji w grupie
     * pomyliłby dwa różne wiersze (kontrakt #2069, punkt 5).
     *
     * @return array{0: Recipe, 1: list<RecipeIngredient>}
     */
    private function przepis(): array
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('autorka2069')->getKey()]);

        $skladniki = [
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'group_name' => 'Ciasto', 'ingredient_text' => '500 g mąki', 'position' => 0, 'note' => 'przesianej']),
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'group_name' => 'Ciasto', 'ingredient_text' => 'sól', 'no_amount' => true, 'position' => 1]),
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'group_name' => 'Farsz', 'ingredient_text' => 'sól', 'no_amount' => true, 'position' => 2]),
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'group_name' => 'Farsz', 'ingredient_text' => '200 g masła', 'position' => 3, 'substitutes' => 'margaryna']),
        ];

        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Zagnieć ciasto.']);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 1, 'instruction' => 'Nałóż farsz.']);

        return [$recipe, $skladniki];
    }

    private function xpath(Recipe $recipe, int $krok = 1): DOMXPath
    {
        $html = (string) $this->get(route('cooking.show', [$recipe->slug, 'krok' => $krok]))->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function jeden(DOMXPath $xpath, string $zapytanie, ?DOMElement $kontekst = null): DOMElement
    {
        $wezly = $xpath->query($zapytanie, $kontekst);
        $this->assertNotFalse($wezly);
        $this->assertSame(1, $wezly->length, "Oczekiwano dokładnie jednego węzła: {$zapytanie}");
        $wezel = $wezly->item(0);
        $this->assertInstanceOf(DOMElement::class, $wezel);

        return $wezel;
    }

    public function test_kazdy_skladnik_ma_wlasne_pole_z_kluczem_po_id_a_nie_po_nazwie(): void
    {
        [$recipe, $skladniki] = $this->przepis();
        $xpath = $this->xpath($recipe);

        $sekcja = $this->jeden($xpath, '//details[@class="cook-ingredients"]');
        $this->assertSame((string) $recipe->getKey(), $sekcja->getAttribute('data-przygotowanie'));

        $wiersze = $xpath->query('.//ul[@class="ingredient-list"]/li[@data-skladnik]', $sekcja);
        $this->assertNotFalse($wiersze);
        $this->assertSame(count($skladniki), $wiersze->length);

        $klucze = [];
        foreach ($wiersze as $i => $li) {
            $this->assertInstanceOf(DOMElement::class, $li);
            $klucze[] = $li->getAttribute('data-skladnik');

            // Etykieta obejmuje CAŁY wiersz: pole, treść, notatkę, zamiennik
            // — więc dotknięcie w dowolnym miejscu linii przełącza pole.
            $etykieta = $this->jeden($xpath, './label[@class="cook-skladnik"]', $li);
            $pole = $this->jeden($xpath, './input[@type="checkbox"][@data-przygotowanie-pole]', $etykieta);
            $this->assertTrue($pole->hasAttribute('hidden'), 'Bez skryptu pole nie może się pokazać (D-053).');
            $this->assertFalse($pole->hasAttribute('checked'), 'Serwer nie zna stanu tej karty — pole przychodzi odznaczone.');
            $this->assertFalse($pole->hasAttribute('name'), 'Pole nie należy do żadnego formularza — nic nie wysyła.');

            // Stan ma też SŁOWO, nie tylko znaczek i kolor (kontrakt #2069).
            $stan = $this->jeden($xpath, './/*[@data-przygotowanie-stan]', $etykieta);
            $this->assertSame('Przygotowane', trim($stan->textContent));
            $this->assertTrue($stan->hasAttribute('hidden'));
        }

        $this->assertSame(array_map(fn (RecipeIngredient $s): string => (string) $s->getKey(), $skladniki), $klucze,
            'Klucz stanu to ID składnika w kolejności listy — dwie „sole” z dwóch grup mają różne klucze.');
    }

    public function test_bez_skryptu_lista_wyglada_jak_dotad_i_nie_ma_zadnej_kontrolki(): void
    {
        [$recipe] = $this->przepis();
        $xpath = $this->xpath($recipe);
        $sekcja = $this->jeden($xpath, '//details[@class="cook-ingredients"]');

        $this->assertFalse($sekcja->hasAttribute('open'), 'Sekcja składników zostaje domyślnie zwinięta.');

        foreach (['data-przygotowanie-wstep', 'data-przygotowanie-akcje', 'data-przygotowanie-podsumowanie'] as $atrybut) {
            $this->assertTrue($this->jeden($xpath, ".//*[@{$atrybut}]", $sekcja)->hasAttribute('hidden'), "{$atrybut} musi być ukryte bez skryptu.");
        }

        // Każda kontrolka checklisty stoi za `hidden` albo w ukrytym kontenerze.
        $kontrolki = $xpath->query('.//input | .//button', $sekcja);
        $this->assertNotFalse($kontrolki);
        $this->assertGreaterThan(0, $kontrolki->length);
        foreach ($kontrolki as $kontrolka) {
            $this->assertInstanceOf(DOMElement::class, $kontrolka);
            $ukryta = $kontrolka->hasAttribute('hidden')
                || $xpath->query('ancestor::*[@hidden]', $kontrolka)?->length > 0;
            $this->assertTrue($ukryta, 'Widoczna bez skryptu kontrolka checklisty byłaby martwym przyciskiem (D-053).');
        }
    }

    public function test_grupy_kolejnosc_notatki_zamienniki_i_do_smaku_zostaja(): void
    {
        [$recipe] = $this->przepis();
        $response = $this->get(route('cooking.show', $recipe->slug))->assertOk();

        $response->assertSeeInOrder([
            '<h3 class="naglowek-grupy">Ciasto</h3>', '500 g mąki', 'przesianej', 'sól', 'do smaku',
            '<h3 class="naglowek-grupy">Farsz</h3>', 'sól', 'do smaku', '200 g masła', 'Zamiast tego: margaryna',
        ], false);
    }

    public function test_wyczyszczenie_skladnikow_nie_jest_resetem_krokow(): void
    {
        [$recipe] = $this->przepis();
        $xpath = $this->xpath($recipe);
        $sekcja = $this->jeden($xpath, '//details[@class="cook-ingredients"]');

        $przycisk = $this->jeden($xpath, './/button[@data-przygotowanie-wyczysc]', $sekcja);
        $this->assertSame('Wyczyść zaznaczenie składników', trim($przycisk->textContent));
        $this->assertSame('button', $przycisk->getAttribute('type'), 'Zwykły przycisk skryptu, nie wysyłka formularza.');
        $this->assertSame(0, $xpath->query('ancestor::form', $przycisk)?->length, 'Nie może stać w formularzu — ani w resecie kroków, ani w żadnym innym.');

        $this->assertSame(0, $xpath->query('.//form', $sekcja)?->length, 'Sekcja składników niczego nie wysyła na serwer.');
    }

    public function test_przepis_bez_skladnikow_nie_ma_checklisty(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('autorka2069b')->getKey()]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok.']);

        $xpath = $this->xpath($recipe);
        $sekcja = $this->jeden($xpath, '//details[@class="cook-ingredients"]');

        $this->assertStringContainsString('Autor jeszcze nie dodał składników.', $sekcja->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-przygotowanie-akcje] | .//input', $sekcja)?->length);
    }
}
