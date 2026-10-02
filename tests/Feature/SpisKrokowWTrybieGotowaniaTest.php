<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\CookedEvent;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Spis kroków” w trybie gotowania (#2441, V2, decyzja właściciela z 2.10.2026).
 *
 * Zwijany `<details>` z linkami GET do tego samego widoku jednego kroku. Wybór
 * kroku niczego nie odhacza, nie gubi porcji, nie tworzy wykonania i — wyjątek
 * rozstrzygnięty w tym zadaniu — nie zasila „Jak wyszło?” (F1), gdy ostatni krok
 * otwarto ze spisu. Każda asercja ujemna ma kontrolę dodatnią obok.
 */
class SpisKrokowWTrybieGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dlugi_przepis_ma_zwiniety_spis_z_numerem_i_fragmentem_kazdego_kroku(): void
    {
        $przepis = $this->przepis(12);

        $odpowiedz = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 12]))->assertOk();
        $xpath = $this->xpath($odpowiedz);

        $spis = $xpath->query('//details[@data-spis-krokow]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $spis);
        $this->assertFalse($spis->hasAttribute('open'), 'Spis ma być domyślnie zwinięty.');
        $this->assertSame('Spis kroków (12)', trim((string) $xpath->query('./summary', $spis)->item(0)?->textContent));

        $linki = $xpath->query('.//ol[@class="cook-spis-lista"]//a', $spis);
        $this->assertSame(12, $linki->length);
        $trzeci = $linki->item(2);
        $this->assertInstanceOf(DOMElement::class, $trzeci);
        $this->assertStringContainsString('Krok 3:', $trzeci->textContent);
        $this->assertStringContainsString('Instrukcja numer 3', $trzeci->textContent);
        $this->assertSame(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 3, 'spis' => 1]), $trzeci->getAttribute('href'));

        // Bieżący krok jest wskazany semantycznie i słowem.
        $biezacy = $xpath->query('.//a[@aria-current="step"]', $spis);
        $this->assertSame(1, $biezacy->length);
        $this->assertStringContainsString('Krok 12 (bieżący):', $biezacy->item(0)->textContent);

        // Spis nie wypiera instrukcji: główna treść to nadal jeden krok.
        $this->assertStringContainsString('Instrukcja numer 12', (string) $odpowiedz->getContent());
    }

    public function test_skok_z_kroku_12_do_3_jednym_wyborem_i_bez_skryptu(): void
    {
        $przepis = $this->przepis(12);
        $strona = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 12]))->assertOk();
        $link = $this->xpath($strona)->query('//details[@data-spis-krokow]//a[contains(., "Krok 3:")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);

        $krokTrzeci = $this->get($link->getAttribute('href'))->assertOk();
        $xpath = $this->xpath($krokTrzeci);

        $this->assertSame('Krok 3 z 12', $this->naglowekKroku($xpath));
        $this->assertSame(1, $xpath->query('//details[@data-spis-krokow]//a[@aria-current="step"][contains(., "Krok 3 (bieżący)")]')->length);
        // Spis i jego linki nie wymagają skryptu: brak `hidden` na spisie.
        $this->assertSame(0, $xpath->query('//details[@data-spis-krokow][@hidden] | //details[@data-spis-krokow]//*[@hidden]')->length);
    }

    public function test_wybrane_porcje_przechodza_przez_linki_spisu(): void
    {
        $przepis = $this->przepis(4);
        $przepis->update(['servings' => 4]);
        $przepis->ingredients()->create(['ingredient_text' => '1 000 g mąki', 'position' => 0]);

        $strona = $this->get(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 4, 'porcje' => 6]))->assertOk();
        $link = $this->xpath($strona)->query('//details[@data-spis-krokow]//a[contains(., "Krok 2:")]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2, 'porcje' => 6, 'spis' => 1]), $link->getAttribute('href'));
        $this->assertStringContainsString('1500 g mąki', strip_tags((string) $this->get($link->getAttribute('href'))->getContent()));
    }

    public function test_wybor_kroku_nie_zmienia_odhaczen_ani_nie_oznacza_kroku_jako_zrobionego(): void
    {
        $przepis = $this->przepis(5);
        $kroki = $przepis->steps()->orderBy('position')->get();
        foreach ([0, 1] as $i) {
            $this->post(route('cooking.zaznacz', $przepis->slug), ['krok' => $i + 1, 'krok_id' => $kroki[$i]->getKey(), 'zrobiono' => 1]);
        }
        $klucz = 'gotowanie.'.$przepis->getKey().'.zrobione';
        $przed = session($klucz);
        $this->assertCount(2, $przed, 'Kontrola: dwa kroki powinny być odhaczone.');

        $this->get(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 4, 'spis' => 1]))->assertOk();

        $this->assertEquals($przed, session($klucz), 'Wybór kroku ze spisu zmienił odhaczenia.');
        // Czwarty krok nie jest zrobiony (przycisk proponuje oznaczenie).
        $this->get(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 4, 'spis' => 1]))->assertSee('Oznacz krok jako zrobiony');
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_ostatni_krok_otwarty_ze_spisu_nie_zasila_jak_wyszlo_a_zwykla_nawigacja_tak(): void
    {
        $przepis = $this->przepis(4);
        $osoba = $this->user('gotujaca');

        $this->actingAs($osoba)->get(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 4, 'spis' => 1]))->assertOk();
        $this->assertSame(0, ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_REACHED)->count(), 'Podgląd ze spisu zasilił F1.');

        // Kontrola dodatnia: ten sam krok bez znacznika spisu jak dotąd.
        $this->actingAs($osoba)->get(route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 4]))->assertOk();
        $this->assertSame(1, ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_REACHED)->count());
    }

    public function test_spis_pokazuje_aktualne_kroki_po_zmianie_kolejnosci_przez_autora(): void
    {
        $przepis = $this->przepis(3);
        $kroki = $przepis->steps()->orderBy('position')->get();
        // Autor zamienia kolejność krokiem 1 i 3 (pozycje unikalne: przez pozycję tymczasową).
        $kroki[0]->update(['position' => 10]);
        $kroki[2]->update(['position' => 0]);
        $kroki[0]->update(['position' => 2]);

        $strona = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk();
        $linki = $this->xpath($strona)->query('//details[@data-spis-krokow]//a');

        $this->assertStringContainsString('Instrukcja numer 3', $linki->item(0)->textContent);
        $this->assertStringContainsString('Instrukcja numer 1', $linki->item(2)->textContent);
    }

    public function test_przepis_z_jednym_krokiem_nie_ma_spisu_a_niepoprawny_parametr_nie_psuje_ekranu(): void
    {
        $jeden = $this->przepis(1);
        $this->assertSame(0, $this->xpath($this->get(route('cooking.show', $jeden->slug))->assertOk())->query('//details[@data-spis-krokow]')->length);

        $wiele = $this->przepis(3);
        $this->get(route('cooking.show', [$wiele->slug, 'krok' => 'abc', 'spis' => 'x']))->assertOk();
        $this->get(route('cooking.show', [$wiele->slug, 'krok' => 99, 'spis' => 1]))->assertOk()->assertSee('Krok 3 z 3');

        // Kontrola dodatnia: przepis z trzema krokami ma spis.
        $this->assertSame(1, $this->xpath($this->get(route('cooking.show', $wiele->slug)))->query('//details[@data-spis-krokow]')->length);
    }

    public function test_cel_spisu_stosuje_aktualna_widocznosc_przepisu(): void
    {
        $autor = $this->user('autor2441');
        $przepis = $this->przepis(4, $autor);
        $obca = $this->user('obca2441');
        $adres = route('cooking.show', ['recipe' => $przepis->slug, 'krok' => 2, 'spis' => 1]);

        $this->actingAs($obca)->get($adres)->assertOk();

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->actingAs($obca)->get($adres)->assertForbidden();
        // Kontrola dodatnia: autor nadal wchodzi.
        $this->actingAs($autor)->get($adres)->assertOk();
    }

    // -----------------------------------------------------------------

    private function przepis(int $liczbaKrokow, ?User $autor = null): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => ($autor ?? $this->user())->getKey()]);

        for ($i = 0; $i < $liczbaKrokow; $i++) {
            RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => 'Instrukcja numer '.($i + 1).' z dłuższym opisem, który w spisie zostaje skrócony.',
            ]);
        }

        return $przepis;
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$odpowiedz->getContent());
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function naglowekKroku(DOMXPath $xpath): string
    {
        $tekst = trim((string) preg_replace('/\s+/u', ' ', $xpath->query('//*[contains(@class, "cook-progress") or contains(@class, "cook-krok-numer")]')->item(0)?->textContent));

        return $tekst;
    }
}
