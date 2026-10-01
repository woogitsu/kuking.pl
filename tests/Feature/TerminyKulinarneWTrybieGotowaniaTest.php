<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\SlownikTerminow;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wyjaśnienia terminów kulinarnych na żądanie w trybie gotowania (#2343).
 *
 * Wariant najostrożniejszy: statyczny słownik z repozytorium, zwykły
 * `<details>`, bez AI, bez zapisu i bez śledzenia. Tryb gotowania jest
 * mierzony pod kątem dostępności przez `scripts/dostepnosc.mjs`
 * (ekran „tryb gotowania”); krok demo „Zagotuj … zbierz łyżką szumowiny”
 * sprawia, że pomiar widzi także ten blok.
 */
class TerminyKulinarneWTrybieGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZKrokiem(string $tresc): Recipe
    {
        $autor = User::factory()->create();
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => $tresc]);

        return $recipe;
    }

    public function test_krok_ze_znanym_terminem_ma_rozwijane_wyjasnienie_dla_goscia_bez_js(): void
    {
        $recipe = $this->przepisZKrokiem('Zrób zasmażkę z masła i mąki, potem zredukuj sos.');

        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<details class="cook-terminy"', $html);
        $this->assertStringContainsString('Wyjaśnij to (2)', $html);
        $this->assertStringContainsString('Mąka podsmażona na tłuszczu', $html);
        $this->assertStringContainsString('Gotować sos albo wywar bez przykrycia', $html);
        // Treść kroku zostaje nietknięta.
        $this->assertStringContainsString('Zrób zasmażkę z masła i mąki, potem zredukuj sos.', $html);
    }

    public function test_krok_bez_znanego_terminu_nie_ma_pustego_przycisku(): void
    {
        $recipe = $this->przepisZKrokiem('Pokrój cebulę i wsyp do garnka.');

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('cook-terminy', false)
            ->assertDontSee('Wyjaśnij to');
    }

    public function test_blok_nie_niesie_zadnego_sledzenia_ani_skryptu_ani_formularza(): void
    {
        $recipe = $this->przepisZKrokiem('Zahartuj śmietanę.');

        $html = (string) $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<details class="cook-terminy".*?<\/details>/s', $html, $blok));
        $this->assertStringNotContainsString('<form', $blok[0]);
        $this->assertStringNotContainsString('<script', $blok[0]);
        $this->assertStringNotContainsString('onclick', $blok[0]);
        $this->assertStringNotContainsString('data-analityka', $blok[0]);
    }

    public function test_rozpoznaje_odmiane_i_nie_lapie_przypadkowych_slow(): void
    {
        $nazwy = fn (string $tekst): array => array_column(SlownikTerminow::wTekscie($tekst), 'haslo');

        $this->assertSame(['Zasmażka'], $nazwy('Do zasmażki dodaj bulion.'));
        $this->assertSame(['Hartowanie'], $nazwy('Zahartowaną śmietanę wlej do zupy.'));
        $this->assertSame(['Szumowiny', 'Redukować'], $nazwy('Zbierz szumowiny, a potem Redukuj płyn.'));
        $this->assertSame([], $nazwy('Zważ mąkę, ugotuj makaron i podaj z masłem.'));
        $this->assertSame([], $nazwy('Połóż ciasto na poduszce z ręcznika.'), 'Rdzeń liczy się od granicy słowa, nie ze środka.');
    }

    public function test_slownik_ma_poprawna_budowe_i_zasady_tekstow(): void
    {
        $nazwy = [];
        foreach (SlownikTerminow::HASLA as $haslo) {
            $this->assertNotSame('', trim($haslo['haslo']));
            $this->assertNotContains($haslo['haslo'], $nazwy, 'Hasło się powtarza.');
            $nazwy[] = $haslo['haslo'];
            $this->assertNotSame([], $haslo['rdzenie']);
            foreach ($haslo['rdzenie'] as $rdzen) {
                $this->assertGreaterThanOrEqual(4, mb_strlen($rdzen), "Rdzeń „{$rdzen}” jest za krótki i łapałby cudze słowa.");
                $this->assertSame(mb_strtolower($rdzen), $rdzen, 'Rdzenie piszemy małymi literami.');
            }
            $this->assertLessThanOrEqual(SlownikTerminow::MAKS_DLUGOSC_WYJASNIENIA, mb_strlen($haslo['wyjasnienie']), "Wyjaśnienie „{$haslo['haslo']}” jest za długie.");
            $this->assertStringEndsWith('.', $haslo['wyjasnienie']);
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])\p{L}*(?:łeś|łaś|łem|łam)(?![\p{L}])/u', $haslo['wyjasnienie'], 'Wyjaśnienie zakłada rodzaj osoby czytającej.');
        }
        $this->assertGreaterThanOrEqual(20, count(SlownikTerminow::HASLA));
    }

    public function test_przykladowe_haslo_demo_jest_rozpoznawane_w_kroku_rosolu(): void
    {
        $this->assertSame(['Szumowiny'], array_column(SlownikTerminow::wTekscie(
            'Zagotuj na średnim ogniu, a gdy zacznie wrzeć, zbierz łyżką szumowiny. To decyduje o tym, czy rosół będzie klarowny.',
        ), 'haslo'));
    }
}
