<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JEDEN KONTRAKT WIERSZA SKŁADNIKA (#878 rozstrzyga #764, wcześniej #44).
 *
 * CO TU JEST PILNOWANE I DLACZEGO AKURAT TO
 * Dwie sprawy, obie wynikające z pomiaru właściciela w #878.
 *
 * 1. TREŚĆ. Składnik z `no_amount` dostawał na ekranie dopisek „— do smaku".
 *    Dla „mleko ile weźmie" i „olej do smażenia" to jest nieprawda: pierwsze
 *    mówi o konsystencji, drugie o zastosowaniu, żadne o doprawianiu.
 *    W miejsce tego stoi neutralne „— bez podanej ilości": nie sugeruje
 *    sposobu dozowania, a nadal odróżnia świadomą decyzję autora od
 *    przeoczenia (o to prosiło #764).
 *
 * 2. JEDNO ŹRÓDŁO. Ten sam wiersz stoi na stronie przepisu i w trybie
 *    gotowania. Dopóki był przepisany w dwóch plikach, dwie gałęzie mogły
 *    nadać mu dwa sprzeczne kontrakty i git nie zgłosił konfliktu — bo
 *    pliki się nie dotykały. Test niżej porównuje wiersze z OBU widoków
 *    znak w znak. Gdyby ktoś znów rozjechał te ekrany, czerwień pokaże to
 *    zanim zobaczy to człowiek w kuchni.
 *
 * DLACZEGO PORÓWNANIE WIERSZY, A NIE SAMO `assertSee`
 * `assertSee('bez podanej ilości')` na obu stronach przechodzi także wtedy,
 * gdy jeden widok pokazuje oznaczenie przy innym składniku niż drugi.
 * Porównanie całych list wierszy łapie również taką rozbieżność.
 */
class WierszSkladnikaJedenKontraktTest extends TestCase
{
    use RefreshDatabase;

    /** Pięć przypadków z pomiaru właściciela w #878, w tej kolejności. */
    private const PRZYPADKI = [
        ['mleko ile weźmie', true],
        ['olej do smażenia', true],
        ['szczypta soli', true],
        ['sól do smaku', true],
        ['200 ml wody', false],
    ];

    private function przepisZPrzypadkami(): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('basia')->getKey()]);

        foreach (self::PRZYPADKI as $position => [$tekst, $bezIlosci]) {
            RecipeIngredient::create([
                'recipe_id' => $recipe->getKey(),
                'ingredient_text' => $tekst,
                'no_amount' => $bezIlosci,
                'position' => $position,
            ]);
        }

        // Tryb gotowania istnieje tylko dla przepisu z krokami.
        RecipeStep::create([
            'recipe_id' => $recipe->getKey(),
            'position' => 0,
            'instruction' => 'Gotuj przez kilka minut.',
        ]);

        return $recipe;
    }

    /**
     * Wiersze listy składników ze strony, jako czysty tekst.
     *
     * @return list<string>
     */
    private function wierszeSkladnikow(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);

        $wiersze = [];
        foreach ($xpath->query('//ul[contains(@class, "ingredient-list")]/li') as $li) {
            $wiersze[] = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
        }

        return $wiersze;
    }

    public function test_oznaczenie_nie_obiecuje_doprawiania_ani_sposobu_dozowania(): void
    {
        $przepis = $this->przepisZPrzypadkami();

        $wiersze = $this->wierszeSkladnikow(
            (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent(),
        );

        $this->assertSame([
            'mleko ile weźmie — bez podanej ilości',
            'olej do smażenia — bez podanej ilości',
            'szczypta soli — bez podanej ilości',
            'sól do smaku — bez podanej ilości',
            '200 ml wody',
        ], $wiersze);
    }

    public function test_ekran_nie_dopisuje_juz_do_smaku_zadnemu_skladnikowi(): void
    {
        $przepis = $this->przepisZPrzypadkami();

        foreach ([route('recipes.show', $przepis->slug), route('cooking.show', $przepis->slug)] as $adres) {
            $html = (string) $this->get($adres)->assertOk()->getContent();

            // „olej do smażenia — do smaku" był dopiskiem zmierzonym w #878.
            // Tekst autora („sól do smaku") zostaje nietknięty, więc szukamy
            // wyłącznie dopisku po myślniku.
            $this->assertStringNotContainsString('— do smaku', $html, $adres.' nadal dopisuje „do smaku”.');
        }
    }

    public function test_strona_przepisu_i_tryb_gotowania_rysuja_ten_sam_wiersz(): void
    {
        $przepis = $this->przepisZPrzypadkami();

        $zeStrony = $this->wierszeSkladnikow(
            (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent(),
        );
        $zGotowania = $this->wierszeSkladnikow(
            (string) $this->get(route('cooking.show', $przepis->slug))->assertOk()->getContent(),
        );

        // Kontrola dodatnia: gdyby któryś widok przestał w ogóle wypisywać
        // składniki, dwie puste listy byłyby sobie równe i test przeszedłby
        // nic nie sprawdzając (patrz „skan, który nie znajduje pliku,
        // przechodzi" w docs/PULAPKI_TESTOW.md).
        $this->assertCount(count(self::PRZYPADKI), $zeStrony);
        $this->assertSame($zeStrony, $zGotowania);
    }

    public function test_notatka_autora_stoi_obok_oznaczenia_na_obu_ekranach(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('basia')->getKey()]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'olej do smażenia',
            'note' => 'na patelnię',
            'no_amount' => true,
            'position' => 0,
        ]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Smaż.']);

        foreach (['recipes.show', 'cooking.show'] as $trasa) {
            $wiersze = $this->wierszeSkladnikow(
                (string) $this->get(route($trasa, $recipe->slug))->assertOk()->getContent(),
            );

            $this->assertSame(['olej do smażenia — bez podanej ilości — na patelnię'], $wiersze);
        }
    }
}
