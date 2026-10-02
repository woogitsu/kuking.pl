<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Widz wybiera liczbę porcji, ilości się przeliczają (D-284, V2).
 *
 * Mierzymy przez PRAWDZIWĄ STRONĘ przepisu, bez JavaScriptu: przyciski
 * „Mniej”/„Więcej” to linki `?porcje=N#skladniki`, więc sam GET musi dać
 * przeliczoną listę, informację „Przeliczone na N porcji” i drogę powrotu.
 * Reguły przeliczania jednego wiersza ma `Tests\Unit\PrzeliczSkladnikTest`;
 * tu sprawdzamy, że strona z nich korzysta i niczego nie zapisuje.
 *
 * KONTROLA UJEMNA (ręcznie): podmiana w widoku `$wyborPorcji->przelicz(...)`
 * na gołe `ingredient_text` oblewa `test_porcje_z_adresu_przeliczaja_liste`
 * („200 g mąki” zamiast „300 g mąki”), a usunięcie `rel="nofollow"`
 * z partiala oblewa `test_bez_parametru_strona_pokazuje_tekst_autora_i_przyciski`.
 */
final class SkalowaniePorcjiNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_bez_parametru_strona_pokazuje_tekst_autora_i_przyciski(): void
    {
        $przepis = $this->przepis(4);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $this->assertSame(
            ['200 g mąki', '2 jajka', 'szczypta soli', 'sól', '1 łyżka masła'],
            $this->skladniki($odpowiedz),
        );

        $odpowiedz->assertSee('Na ile porcji?')
            ->assertSee('4 porcje')
            ->assertDontSee('Przeliczone na');

        $xpath = $this->xpath($odpowiedz);
        $mniej = $xpath->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Mniej")]')->item(0);
        $wiecej = $xpath->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Więcej")]')->item(0);

        $this->assertNotNull($mniej, 'Brak linku „Mniej”.');
        $this->assertNotNull($wiecej, 'Brak linku „Więcej”.');
        $this->assertStringEndsWith('?porcje=3#skladniki', self::elementDom($mniej)->getAttribute('href'));
        $this->assertStringEndsWith('?porcje=5#skladniki', self::elementDom($wiecej)->getAttribute('href'));
        $this->assertSame('nofollow', self::elementDom($wiecej)->getAttribute('rel'), 'Warianty porcji nie mają trafiać do wyszukiwarki.');
        $this->assertStringContainsString('Mniej', (string) self::elementDom($mniej)->getAttribute('aria-label'), 'Nazwa dostępna musi zawierać widoczny napis (WCAG 2.5.3).');
    }

    public function test_porcje_z_adresu_przeliczaja_liste(): void
    {
        // Ze zdjęciem, bo `Recipe` w JSON-LD jest tylko przy gotowym zdjęciu (#1005).
        $przepis = $this->przepis(4, zeZdjeciem: true);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 6]))->assertOk();

        $this->assertSame(
            ['300 g mąki', '3 jajka', 'szczypta soli', 'sól', '1½ łyżki masła'],
            $this->skladniki($odpowiedz),
        );

        $odpowiedz->assertSee('Przeliczone na 6 porcji.')
            ->assertSee('Autor podał ilości na 4 porcje.')
            ->assertSee('Szczypta, „do smaku” i składniki bez liczby zostały bez zmian.', false);

        $xpath = $this->xpath($odpowiedz);

        // Wyróżniona jest SAMA przeliczona ilość, reszta to zdanie autora.
        $wyroznione = [];
        foreach ($xpath->query('//strong[@class="skladnik-przeliczony"]') as $strong) {
            $wyroznione[] = $strong->textContent;
        }
        $this->assertSame(['300 g', '3', '1½ łyżki'], $wyroznione);

        $powrot = $xpath->query('//a[normalize-space(.)="Pokaż ilości z przepisu"]')->item(0);
        $this->assertNotNull($powrot);
        $this->assertSame(route('recipes.show', $przepis->slug).'#skladniki', self::elementDom($powrot)->getAttribute('href'));

        // Canonical i dane dla wyszukiwarek zostają przy przepisie autora.
        $canonical = $xpath->query('//link[@rel="canonical"]')->item(0);
        $this->assertSame(route('recipes.show', $przepis->slug), self::elementDom($canonical)->getAttribute('href'));
        $jsonLd = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $skrypt) {
            $jsonLd[] = json_decode($skrypt->textContent, true);
        }
        $przepisLd = collect($jsonLd)->firstWhere('@type', 'Recipe');
        $this->assertNotNull($przepisLd, 'Brak JSON-LD przepisu — asercja niżej nic by nie mierzyła.');
        $this->assertSame('200 g mąki', $przepisLd['recipeIngredient'][0]);

        // Przeliczenie jest widokiem, nie zapisem.
        $this->assertSame(
            ['200 g mąki', '2 jajka', 'szczypta soli', 'sól', '1 łyżka masła'],
            $przepis->ingredients()->orderBy('position')->pluck('ingredient_text')->all(),
        );
    }

    public function test_koszt_autora_i_skladniki_uzywaja_tego_samego_wyboru_porcji_takze_w_wydruku(): void
    {
        $przepis = $this->przepis(4, ['estimated_cost_pln' => 24]);

        foreach ([
            [null, '200 g mąki', 'Szacunkowy koszt: ok. 24 zł (wg autora)'],
            ['2', '100 g mąki', 'Szacunkowy koszt: ok. 12 zł (przeliczone z kosztu podanego przez autora)'],
            ['6', '300 g mąki', 'Szacunkowy koszt: ok. 36 zł (przeliczone z kosztu podanego przez autora)'],
            ['4', '200 g mąki', 'Szacunkowy koszt: ok. 24 zł (wg autora)'],
            ['abc', '200 g mąki', 'Szacunkowy koszt: ok. 24 zł (wg autora)'],
        ] as [$porcje, $skladnik, $koszt]) {
            foreach ([false, true] as $druk) {
                $parametry = ['recipe' => $przepis->slug];
                if ($porcje !== null) {
                    $parametry['porcje'] = $porcje;
                }
                if ($druk) {
                    $parametry['druk'] = 1;
                }

                $odpowiedz = $this->get(route('recipes.show', $parametry))->assertOk();
                $this->assertSame($skladnik, $this->skladniki($odpowiedz)[0]);
                $this->assertSame($koszt, $this->kosztAutora($odpowiedz), 'KOSZT_AUTORA_2524_NIEPRZELICZONY');
            }
        }

        $this->assertSame(24.0, $przepis->fresh()->estimated_cost_pln);
        $this->assertSame('200 g mąki', $przepis->ingredients()->orderBy('position')->firstOrFail()->ingredient_text);
    }

    public function test_koszt_autora_przy_ulamkowych_porcjach_zero_i_braku_podstawy(): void
    {
        $ulamkowy = $this->przepis(4, ['servings' => 2.5, 'estimated_cost_pln' => 24]);
        $this->assertSame(
            'Szacunkowy koszt: ok. 48 zł (przeliczone z kosztu podanego przez autora)',
            $this->kosztAutora($this->get(route('recipes.show', ['recipe' => $ulamkowy->slug, 'porcje' => 5]))->assertOk()),
        );

        $zero = $this->przepis(4, ['estimated_cost_pln' => 0]);
        $this->assertSame(
            'Szacunkowy koszt: ok. 0 zł (przeliczone z kosztu podanego przez autora)',
            $this->kosztAutora($this->get(route('recipes.show', ['recipe' => $zero->slug, 'porcje' => 6]))->assertOk()),
        );

        $bezPorcji = $this->przepis(null, ['estimated_cost_pln' => 24]);
        $this->assertSame(
            'Szacunkowy koszt: ok. 24 zł (wg autora)',
            $this->kosztAutora($this->get(route('recipes.show', ['recipe' => $bezPorcji->slug, 'porcje' => 6]))->assertOk()),
        );

        $bezKosztu = $this->przepis(4);
        $this->assertSame(
            '',
            $this->kosztAutora($this->get(route('recipes.show', ['recipe' => $bezKosztu->slug, 'porcje' => 6]))->assertOk()),
        );
    }

    public function test_liczba_z_przepisu_w_adresie_to_brak_przeliczenia(): void
    {
        $przepis = $this->przepis(4);

        $this->get(route('recipes.show', [$przepis->slug, 'porcje' => '4']))
            ->assertOk()
            ->assertDontSee('Przeliczone na')
            ->assertDontSee('Tej liczby porcji nie da się przeliczyć');
    }

    public function test_przy_jednej_porcji_mniej_jest_wylaczone_a_nie_martwe(): void
    {
        $przepis = $this->przepis(4);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 1]))->assertOk();
        $xpath = $this->xpath($odpowiedz);

        $this->assertSame(0, $xpath->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Mniej")]')->length, '„Mniej” przy 1 porcji nie może prowadzić do 0.');
        $this->assertSame(1, $xpath->query('//div[@class="porcje-wybor-przyciski"]/span[@aria-disabled="true"][contains(., "Mniej")]')->length);

        $odpowiedz->assertSee('Przeliczone na 1 porcję.');
        $this->assertSame(['50 g mąki', '½ jajka', 'szczypta soli', 'sól', '¼ łyżki masła'], $this->skladniki($odpowiedz));
    }

    public function test_porcje_ulamkowe_przechodza_do_pelnych_liczb(): void
    {
        $przepis = $this->przepis(4);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => '2,5']))->assertOk();
        $xpath = $this->xpath($odpowiedz);

        $odpowiedz->assertSee('Przeliczone na 2,5 porcji.');
        $this->assertStringEndsWith('?porcje=2#skladniki', self::elementDom($xpath->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Mniej")]')->item(0))->getAttribute('href'));
        $this->assertStringEndsWith('?porcje=3#skladniki', self::elementDom($xpath->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Więcej")]')->item(0))->getAttribute('href'));
    }

    public function test_nieuzywalna_liczba_pokazuje_przepis_autora_i_mowi_co_zrobic(): void
    {
        $przepis = $this->przepis(4);

        foreach (['abc', '0', '500', '-2', '3.333'] as $zle) {
            $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => $zle]))->assertOk();

            $odpowiedz->assertSee('Tej liczby porcji nie da się przeliczyć.')
                ->assertSee('Wybierz od 1 do 100 przyciskami „Mniej” i „Więcej”.', false)
                ->assertDontSee('Przeliczone na');

            $this->assertSame('200 g mąki', $this->skladniki($odpowiedz)[0], "Dla ?porcje={$zle}");
        }
    }

    public function test_przepis_bez_liczby_porcji_nie_ma_wyboru(): void
    {
        $przepis = $this->przepis(null);

        $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 6]))
            ->assertOk()
            ->assertDontSee('Na ile porcji?')
            ->assertDontSee('Przeliczone na')
            ->assertSee('200 g mąki');
    }

    public function test_parametr_porcji_nie_omija_policy(): void
    {
        // UUID/slug i parametr w adresie to nie autoryzacja (AGENTS.md §7).
        $przepis = $this->przepis(4, ['visibility' => 'private']);

        $this->actingAs($this->user('ktos_obcy'))
            ->get(route('recipes.show', [$przepis->slug, 'porcje' => 6]))
            ->assertForbidden();
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function przepis(?int $porcje, array $atrybuty = [], bool $zeZdjeciem = false): Recipe
    {
        $fabryka = $zeZdjeciem ? Recipe::factory()->zeZdjeciem() : Recipe::factory();

        $przepis = $fabryka->create([
            'author_id' => $this->user()->getKey(),
            'servings' => $porcje,
            ...$atrybuty,
        ]);

        foreach ([
            ['200 g mąki', false],
            ['2 jajka', false],
            ['szczypta soli', false],
            ['sól', true],
            ['1 łyżka masła', false],
        ] as $pozycja => [$tekst, $bezIlosci]) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'ingredient_text' => $tekst,
                'no_amount' => $bezIlosci,
                'position' => $pozycja,
            ]);
        }

        return $przepis;
    }

    /** @return list<string> tekst każdego składnika bez linii zamiennika */
    private function skladniki(TestResponse $odpowiedz): array
    {
        $wynik = [];

        foreach ($this->xpath($odpowiedz)->query('//ul[@class="ingredient-list"]/li') as $li) {
            foreach ((new DOMXPath($li->ownerDocument))->query('.//span', $li) as $span) {
                $span->parentNode?->removeChild($span);
            }

            $wynik[] = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
        }

        return $wynik;
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$odpowiedz->getContent());

        return new DOMXPath($dom);
    }

    private function kosztAutora(TestResponse $odpowiedz): string
    {
        $element = $this->xpath($odpowiedz)->query('//p[@data-koszt-autora]')->item(0);

        return trim($element->textContent ?? '');
    }
}
