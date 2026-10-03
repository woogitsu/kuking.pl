<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeStep;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dodatkowy czas przy minutniku kroku (#2458, decyzja właściciela z 2.10.2026):
 * markup w trybie gotowania. Logika odliczania (dodanie do pozostałego czasu,
 * granica upływu, przeładowanie, kolejka) jest sprawdzana w Node na
 * kontrolowanym zegarze — `resources/js/odliczanie-minutnika.test.mjs`.
 *
 * Tu pilnujemy tego, co widzi serwer: kontrolki są i są ukryte bez skryptu
 * (D-053 — żadnej martwej kontrolki), pole ma widoczną etykietę, formularz
 * `novalidate`, a zdanie o kuchennym minutniku zostaje jako baza bez JS.
 */
final class MinutnikDodatkowyCzasTest extends TestCase
{
    use RefreshDatabase;

    public function test_minutnik_autora_ma_ukryte_bez_skryptu_kontrolki_dodatkowego_czasu_i_zdanie_o_kuchennym_minutniku(): void
    {
        $przepis = $this->przepisZKrokiem(timerSekundy: 2400);

        $odpowiedz = $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk();
        $xpath = $this->xpath($odpowiedz);

        $this->assertSame(1, $xpath->query('//div[contains(@class, "cook-timer") and @data-timer-sekundy="2400"]')->length);
        $odpowiedz->assertSee('Ustaw sobie kuchenny minutnik na');
        $this->sprawdzKontrolki($xpath, '//div[@data-timer-sekundy="2400"]');
    }

    public function test_wlasny_minutnik_przy_kroku_bez_czasu_autora_ma_te_same_kontrolki(): void
    {
        $przepis = $this->przepisZKrokiem(timerSekundy: null);

        $xpath = $this->xpath($this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk());

        $this->sprawdzKontrolki($xpath, '//div[@data-timer-wlasny="1"]');
    }

    public function test_kontrolki_nie_zmieniaja_czasu_autora_ani_postepu_w_odpowiedzi_serwera(): void
    {
        $przepis = $this->przepisZKrokiem(timerSekundy: 2400);
        $krok = $przepis->steps()->firstOrFail();

        $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk();

        $this->assertSame(2400, $krok->fresh()->timer_seconds, 'Serwer nie zmienia czasu autora.');
        $this->assertSame(0, CookedEvent::query()->count());
    }

    private function sprawdzKontrolki(DOMXPath $xpath, string $blok): void
    {
        $przycisk = $xpath->query($blok.'//button[contains(@class, "cook-timer-dodaj")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $przycisk);
        $this->assertTrue($przycisk->hasAttribute('hidden'), 'Przycisk dodatkowego czasu ma być ukryty bez skryptu.');
        $this->assertSame('Ustaw dodatkowy czas', trim((string) preg_replace('/\s+/u', ' ', $przycisk->textContent)));

        $formularz = $xpath->query($blok.'//form[contains(@class, "cook-timer-dodatkowy")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz);
        $this->assertTrue($formularz->hasAttribute('hidden'));
        $this->assertTrue($formularz->hasAttribute('novalidate'));

        $etykieta = $xpath->query('.//label[@for="f-minutnik-dodatkowy"]', $formularz)->item(0);
        $this->assertSame('Ile dodatkowych minut?', trim((string) $etykieta?->textContent), 'Widoczna etykieta pola.');
        $pole = $xpath->query('.//input[@id="f-minutnik-dodatkowy"]', $formularz)->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole);
        $this->assertSame('text', $pole->getAttribute('type'));
        $this->assertStringContainsString('f-minutnik-dodatkowy-blad', $pole->getAttribute('aria-describedby'));

        // Błąd przy polu i w podsumowaniu (na górze formularza) — oba ukryte do pierwszego błędu.
        foreach (['.cook-timer-dodatkowy-blad', '.cook-timer-dodatkowy-podsumowanie'] as $klasa) {
            $blad = $xpath->query('.//*[contains(@class, "'.ltrim($klasa, '.').'")]', $formularz)->item(0);
            $this->assertInstanceOf(DOMElement::class, $blad, "Brak {$klasa}.");
            $this->assertTrue($blad->hasAttribute('hidden'));
        }

        $napisy = array_map(
            static fn ($b): string => trim((string) preg_replace('/\s+/u', ' ', $b->textContent)),
            iterator_to_array($xpath->query('.//button', $formularz)),
        );
        $this->assertSame(['Dodaj czas', 'Nie dodawaj'], $napisy);
    }

    private function przepisZKrokiem(?int $timerSekundy): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);
        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Piecz do zrumienienia.',
            'timer_seconds' => $timerSekundy,
        ]);

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
}
