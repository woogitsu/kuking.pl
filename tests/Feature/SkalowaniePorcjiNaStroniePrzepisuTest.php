<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
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

    public function test_mniej_z_duzej_liczby_autora_prowadzi_do_przyjetych_stu_porcji(): void
    {
        $przepis = $this->przepis(150);
        $pierwsza = $this->get(route('recipes.show', $przepis->slug))->assertOk();
        $mniej = $this->xpath($pierwsza)->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Mniej")]')->item(0);

        $this->assertNotNull($mniej);
        $adres = self::elementDom($mniej)->getAttribute('href');
        $this->assertStringEndsWith('?porcje=100#skladniki', $adres, 'PORCJE_2624_MNIEJ_BEZ_PETLI');
        $this->assertSame('Mniej porcji: 100 porcji', self::elementDom($mniej)->getAttribute('aria-label'));

        $druga = $this->get(explode('#', $adres)[0])->assertOk();
        $druga->assertSee('Przeliczone na 100 porcji.')
            ->assertDontSee('Tej liczby porcji nie da się przeliczyć.')
            ->assertSee('Pokaż ilości z przepisu');
        $this->assertSame('Mniej porcji: 99 porcji', self::elementDom(
            $this->xpath($druga)->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Mniej")]')->item(0),
        )->getAttribute('aria-label'));
        $this->assertSame(0, $this->xpath($druga)->query('//div[@class="porcje-wybor-przyciski"]/a[contains(., "Więcej")]')->length);
    }

    public function test_mniej_i_wiecej_oferuja_tylko_liczby_akceptowane_przez_wybor(): void
    {
        foreach ([
            [0.5, null, 1.0],
            [1, null, 2.0],
            [100, 99.0, null],
            [101, 100.0, null],
            [102, 100.0, null],
            [150, 100.0, null],
            [999, 100.0, null],
            [101.5, 100.0, null],
        ] as [$autora, $oczekiwaneMniej, $oczekiwaneWiecej]) {
            $przepis = Recipe::factory()->make(['servings' => $autora]);
            $wybor = WyborPorcji::dla($przepis, null);

            $this->assertSame($oczekiwaneMniej, $wybor->mniej(), "Mniej od {$autora} porcji.");
            $this->assertSame($oczekiwaneWiecej, $wybor->wiecej(), "Więcej od {$autora} porcji.");
            foreach ([$wybor->mniej(), $wybor->wiecej()] as $nastepne) {
                if ($nastepne !== null) {
                    $this->assertFalse(WyborPorcji::dla($przepis, $wybor->doAdresu($nastepne))->odrzucone);
                }
            }
            $this->assertNull($wybor->doAdresu((float) $autora), 'Powrót do oryginału nie ma parametru porcji.');
        }
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
                ->assertSee('Wpisz od 1 do 100 w polu albo użyj przycisków „Mniej” i „Więcej”.', false)
                ->assertDontSee('Przeliczone na');

            $this->assertSame('200 g mąki', $this->skladniki($odpowiedz)[0], "Dla ?porcje={$zle}");
        }
    }

    public function test_pole_na_ile_porcji_ma_etykiete_biezaca_wartosc_i_przycisk(): void
    {
        $przepis = $this->przepis(4);

        $xpath = $this->xpath($this->get(route('recipes.show', $przepis->slug))->assertOk());

        $formularz = self::elementDom($xpath->query('//form[contains(@class,"porcje-wybor-pole")]')->item(0));
        $this->assertSame('get', strtolower($formularz->getAttribute('method')));
        $this->assertSame(route('recipes.show', $przepis->slug).'#skladniki', $formularz->getAttribute('action'));

        $pole = self::elementDom($xpath->query('//input[@id="porcje-wybor-pole"]')->item(0));
        $this->assertSame('porcje', $pole->getAttribute('name'));
        $this->assertSame('decimal', $pole->getAttribute('inputmode'));
        $this->assertSame('4', $pole->getAttribute('value'));
        $this->assertSame('Na ile porcji?', trim($xpath->query('//label[@for="porcje-wybor-pole"]')->item(0)->textContent));
        $this->assertSame('Przelicz', trim($xpath->query('//form[contains(@class,"porcje-wybor-pole")]//button[@type="submit"]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//input[@id="porcje-wybor-pole"][@placeholder]')->length, 'Etykieta nie może być placeholderem.');
    }

    public function test_wartosc_z_pola_przelicza_ilosci_a_pole_zachowuje_liczbe(): void
    {
        $przepis = $this->przepis(4);

        foreach (['20' => '1000 g mąki', '20,0' => '1000 g mąki', '2,5' => '130 g mąki'] as $wpisane => $maka) {
            $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => $wpisane]))->assertOk();

            $this->assertSame($maka, $this->skladniki($odpowiedz)[0], "Dla ?porcje={$wpisane}");
            $odpowiedz->assertDontSee('porcje-wybor-blad', false);
        }

        $xpath = $this->xpath($this->get(route('recipes.show', [$przepis->slug, 'porcje' => '20']))->assertOk());
        $this->assertSame('20', self::elementDom($xpath->query('//input[@id="porcje-wybor-pole"]')->item(0))->getAttribute('value'));
    }

    public function test_zle_wartosci_z_pola_dostaja_komunikat_przy_polu_i_zostaja_w_polu(): void
    {
        $przepis = $this->przepis(4);

        foreach (['0', '101', 'abc', '1e2', '-3'] as $zle) {
            $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => $zle]))->assertOk();
            $xpath = $this->xpath($odpowiedz);

            $blad = $xpath->query('//p[@id="porcje-wybor-blad"]')->item(0);
            $this->assertNotNull($blad, "Brak komunikatu przy polu dla {$zle}");
            $this->assertStringContainsString('Wpisz liczbę od 1 do 100', $blad->textContent);

            $pole = self::elementDom($xpath->query('//input[@id="porcje-wybor-pole"]')->item(0));
            $this->assertSame($zle, $pole->getAttribute('value'), 'Wpisana wartość ma zostać w polu.');
            $this->assertSame('true', $pole->getAttribute('aria-invalid'));
            $this->assertSame('200 g mąki', $this->skladniki($odpowiedz)[0], 'Przy błędzie ilości zostają autora.');
        }

        // Tablica w adresie (`?porcje[]=5`): middleware wycina ją przed widokiem —
        // bez błędu 500 i z ilościami autora.
        $tablica = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => [5]]))->assertOk();
        $this->assertSame('200 g mąki', $this->skladniki($tablica)[0]);
    }

    public function test_liczba_z_pola_przechodzi_do_trybu_gotowania(): void
    {
        $przepis = $this->przepis(4);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Krok.']);

        $odpowiedz = $this->get(route('recipes.show', [$przepis->slug, 'porcje' => '20']))->assertOk();

        $this->assertStringContainsString(
            route('cooking.show', ['recipe' => $przepis->slug, 'porcje' => '20']),
            html_entity_decode($odpowiedz->getContent()),
        );
    }

    public function test_pole_zachowuje_kontekst_kartki_pomocnika(): void
    {
        $przepis = $this->przepis(4);

        $xpath = $this->xpath($this->get(route('recipes.show', [$przepis->slug, 'druk' => 1, 'dla' => 'pomocnika']))->assertOk());

        $this->assertSame('1', self::elementDom($xpath->query('//form[contains(@class,"porcje-wybor-pole")]/input[@name="druk"]')->item(0))->getAttribute('value'));
        $this->assertSame('pomocnika', self::elementDom($xpath->query('//form[contains(@class,"porcje-wybor-pole")]/input[@name="dla"]')->item(0))->getAttribute('value'));
    }

    public function test_przepis_bez_liczby_porcji_nie_ma_wyboru(): void
    {
        $przepis = $this->przepis(null);

        $this->get(route('recipes.show', [$przepis->slug, 'porcje' => 6]))
            ->assertOk()
            ->assertDontSee('Na ile porcji?')
            ->assertDontSee('porcje-wybor-pole', false)
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
            foreach ((new DOMXPath($li->ownerDocument))->query('.//span | .//details', $li) as $span) {
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
