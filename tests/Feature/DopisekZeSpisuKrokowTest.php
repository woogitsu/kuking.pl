<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Models\CookedEvent;
use App\Models\CookingNote;
use App\Models\Notification;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\RecipeStep;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** Pomocniczy POST z podglądu ostatniego kroku nie kończy gotowania (#2858). */
final class DopisekZeSpisuKrokowTest extends TestCase
{
    use RefreshDatabase;

    public function test_blad_dopisku_wraca_przez_rzeczywisty_link_spisu_bez_pytania_na_starcie(): void
    {
        $przepis = $this->przepis();
        $osoba = $this->user();
        $pierwsza = $this->actingAs($osoba)->get(route('cooking.show', [$przepis->slug, 'krok' => 1, 'porcje' => 6]))->assertOk();
        $link = $this->element($this->xpath($pierwsza)->query('//details[@data-spis-krokow]//a[contains(., "Krok 2:")]')->item(0))->getAttribute('href');
        $podglad = $this->actingAs($osoba)->get($link)->assertOk();
        $formularz = $this->formularz($podglad, 'cooking.dopisek.zapisz', $przepis);
        $this->assertEquals(['krok' => '2', 'porcje' => '6', 'spis' => '1', 'rewizja' => '0'], $formularz, 'SPIS_2858_FORMULARZ_NIESIE_PODGLAD');
        $this->assertSame(0, $this->sygnaly());

        $tekst = str_repeat('a', RoboczyDopisek::MAKS_ZNAKOW + 1);
        $odmowa = $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), $formularz + ['dopisek' => $tekst]);
        $odmowa->assertSessionHasErrors('dopisek')->assertSessionHasInput('dopisek', $tekst);
        $cel = route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2, 'porcje' => 6, 'spis' => 1]);
        $odmowa->assertRedirect();
        $this->assertSame($cel, $odmowa->headers->get('Location'), 'SPIS_2858_BLEDNY_DOPISEK_WRACA_DO_PODGLADU');
        $powrot = $this->actingAs($osoba)->get($cel)->assertOk()->assertSee($tekst);
        $this->assertSame(1, $this->xpath($powrot)->query('//details[contains(@class, "cook-dopisek")][@open]')->length);
        $this->assertSame(0, $this->sygnaly(), 'SPIS_2858_BLEDNY_DOPISEK_BEZ_F1');
        $this->assertSame(0, $this->xpath($this->actingAs($osoba)->get(route('home'))->assertOk())->query('//section[contains(@class, "jak-wyszlo")]')->length);
        $this->assertSame(0, $this->sygnaly());
        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_zapis_konflikt_i_usuniecie_zachowuja_podglad_a_zwykly_koniec_nadal_pyta(): void
    {
        $przepis = $this->przepis();
        $osoba = $this->user();
        $podglad = $this->actingAs($osoba)->get(route('cooking.show', [$przepis->slug, 'krok' => 2, 'spis' => 1]))->assertOk();
        $formularz = $this->formularz($podglad, 'cooking.dopisek.zapisz', $przepis);
        $cel = route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2, 'spis' => 1]);

        $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), $formularz + ['dopisek' => 'Pierwsza uwaga'])
            ->assertRedirect($cel);
        $this->assertSame(0, $this->sygnaly());
        $zapisany = $this->actingAs($osoba)->get($cel)->assertOk();
        $usun = $this->formularz($zapisany, 'cooking.dopisek.usun', $przepis);
        $this->assertSame(['krok' => '2', 'spis' => '1'], $usun);

        $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), $this->formularz($zapisany, 'cooking.dopisek.zapisz', $przepis) + ['dopisek' => 'Druga uwaga'])
            ->assertRedirect($cel);
        $konflikt = $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), $formularz + ['dopisek' => 'Tekst ze starej karty']);
        $konflikt->assertRedirect($cel)->assertSessionHasInput('dopisek', 'Tekst ze starej karty');
        $this->actingAs($osoba)->get($cel)->assertOk()->assertSee('Tekst ze starej karty')->assertSee('Druga uwaga');

        $this->actingAs($osoba)->post(route('cooking.dopisek.usun', $przepis->slug), $usun)->assertRedirect($cel);
        $this->actingAs($osoba)->get($cel)->assertOk();
        $this->assertSame(0, CookingNote::query()->where('user_id', $osoba->getKey())->where('recipe_id', $przepis->getKey())->count());

        $this->assertSame(0, $this->sygnaly(), 'SPIS_2858_WSZYSTKIE_POWROTY_BEZ_F1');

        $this->actingAs($osoba)->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))->assertOk();
        $this->assertSame(1, $this->sygnaly(), 'Zwykłe dojście do końca nadal tworzy F1.');
        $this->actingAs($osoba)->get($cel)->assertOk();
        $this->assertSame(1, $this->sygnaly(), 'Podgląd nie kasuje poprawnego wcześniejszego sygnału.');
    }

    public function test_awaria_zapisu_zachowuje_tekst_i_podglad(): void
    {
        $przepis = $this->przepis();
        $osoba = $this->user();
        $cel = route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2, 'spis' => 1]);
        $podglad = $this->actingAs($osoba)->get($cel)->assertOk();
        $formularz = $this->formularz($podglad, 'cooking.dopisek.zapisz', $przepis);
        $this->mock(RoboczyDopisek::class, function ($mock): void {
            $mock->shouldReceive('aktywny')->andReturn(null);
            $mock->shouldReceive('zapisz')->andThrow(new RuntimeException('testowa awaria zapisu'));
        });

        $awaria = $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), $formularz + ['dopisek' => 'Tekst po awarii']);
        $awaria->assertRedirect($cel)->assertSessionHasInput('dopisek', 'Tekst po awarii');
        $this->actingAs($osoba)->get($cel)->assertOk()->assertSee('Tekst po awarii');
        $this->assertSame(0, $this->sygnaly(), 'SPIS_2858_AWARIA_BEZ_F1');
    }

    public function test_klient_nie_moze_podac_obcego_adresu_powrotu_ani_nieprawdziwego_znacznika(): void
    {
        $przepis = $this->przepis();
        $osoba = $this->user();
        $odpowiedz = $this->actingAs($osoba)->post(route('cooking.dopisek.zapisz', $przepis->slug), [
            'dopisek' => ' ', 'krok' => 99, 'spis' => 'https://obca.example/wyjscie', 'powrot' => 'https://obca.example/wyjscie',
        ]);
        $odpowiedz->assertRedirect(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2]));
        $this->assertSame(0, $this->sygnaly());
    }

    private function przepis(): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user()->getKey(), 'servings' => 4]);
        foreach ([0, 1] as $i) {
            RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => $i, 'instruction' => 'Instrukcja kroku '.($i + 1).'.']);
        }

        return $przepis;
    }

    /** @return array<string, string> */
    private function formularz(TestResponse $odpowiedz, string $trasa, Recipe $przepis): array
    {
        $xpath = $this->xpath($odpowiedz);
        $cel = route($trasa, $przepis->slug);
        $forms = $xpath->query('//form[@action="'.$cel.'"]');
        $form = $this->element($forms->item(0));
        $pola = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
            $element = $this->element($input);
            if ($element->getAttribute('name') !== '_token') {
                $pola[$element->getAttribute('name')] = $element->getAttribute('value');
            }
        }

        return $pola;
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.(string) $odpowiedz->getContent());
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function element(?\DOMNode $wezel): DOMElement
    {
        $this->assertInstanceOf(DOMElement::class, $wezel);

        return $wezel;
    }

    private function sygnaly(): int
    {
        return ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_REACHED)->count();
    }
}
