<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pusty formularz rejestracji dochodzi do polskiego podsumowania błędów (#2243).
 *
 * Pola rejestracji mają natywne `required` (z `x-field`), a formularz nie
 * miał `novalidate` — przeglądarka zatrzymywała pusty formularz przed
 * wysłaniem i pokazywała własny dymek, więc `x-error-summary` i polskie
 * błędy przy polach nie miały jak się pokazać. PHPUnit nie uruchamia
 * walidacji przeglądarki, więc test sprawdza obie połowy kontraktu:
 *
 *  1. formularz ma `novalidate`, a pola dalej mają `required` (czytnik
 *     ekranu mówi „wymagane”, etykieta ma dopisek „(wymagane)”);
 *  2. pusty POST, który teraz naprawdę dochodzi do serwera, wraca z
 *     podsumowaniem po polsku, w którym KAŻDY odnośnik prowadzi do
 *     istniejącego pola z `aria-invalid` — więc fokus i przejście z
 *     podsumowania do pola działają.
 */
class RejestracjaPolskiePodsumowanieBledowTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function formularz(DOMXPath $xpath): DOMElement
    {
        $formularze = $xpath->query('//form[@action="'.route('register').'"]');
        $this->assertSame(1, $formularze->length, 'Na stronie rejestracji nie ma formularza rejestracji.');

        $formularz = $formularze->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz);

        return $formularz;
    }

    public function test_przegladarka_nie_zatrzymuje_formularza_a_pola_zostaja_wymagane(): void
    {
        $xpath = $this->xpath((string) $this->get(route('register'))->assertOk()->getContent());
        $formularz = $this->formularz($xpath);

        $this->assertTrue($formularz->hasAttribute('novalidate'),
            'Formularz rejestracji nie ma novalidate — przeglądarka zatrzyma pusty formularz własnym dymkiem, zanim serwer odeśle polskie podsumowanie błędów.');

        foreach (['f-display_name', 'f-username', 'f-email', 'f-password'] as $id) {
            $pole = $xpath->query('.//input[@id="'.$id.'"]', $formularz);
            $this->assertSame(1, $pole->length, "Nie ma pola {$id} w formularzu rejestracji.");
            $this->assertTrue($this->element($pole->item(0))->hasAttribute('required'), "Pole {$id} straciło required — czytnik ekranu nie powie, że jest wymagane.");
        }
    }

    public function test_pusty_formularz_wraca_z_polskim_podsumowaniem_prowadzacym_do_pol(): void
    {
        $html = (string) $this->from(route('register'))
            ->followingRedirects()
            ->post(route('register'), [])
            ->assertOk()
            ->getContent();

        $this->assertSame(0, User::query()->count());

        $xpath = $this->xpath($html);
        $podsumowanie = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]');
        $this->assertSame(1, $podsumowanie->length, 'Pusty formularz rejestracji nie pokazał podsumowania błędów.');
        $this->assertSame('-1', $this->element($podsumowanie->item(0))->getAttribute('tabindex'), 'Podsumowanie musi przyjąć fokus (resources/js/app.js).');

        $cele = [];
        foreach ($xpath->query('.//a', $podsumowanie->item(0)) as $odnosnik) {
            $tekst = trim($odnosnik->textContent);
            $this->assertDoesNotMatchRegularExpression('~validation\.|The .* field|required~i', $tekst, 'Komunikat w podsumowaniu nie jest po polsku.');
            $cele[] = ltrim($this->element($odnosnik)->getAttribute('href'), '#');
        }

        foreach (['f-display_name', 'f-username', 'f-email', 'f-password', 'f-age_confirmed', 'f-terms_accepted'] as $id) {
            $this->assertContains($id, $cele, "Podsumowanie nie prowadzi do pola {$id}.");
        }

        $formularz = $this->formularz($xpath);
        foreach ($cele as $id) {
            $pole = $xpath->query('.//*[@id="'.$id.'"]', $formularz);
            $this->assertSame(1, $pole->length, "Odnośnik z podsumowania prowadzi do #{$id}, którego nie ma w formularzu.");
            $this->assertSame('true', $this->element($pole->item(0))->getAttribute('aria-invalid'), "Pole #{$id} nie jest oznaczone jako błędne.");
        }
    }

    /** Węzeł z XPath jako element — z asercją zamiast cichego założenia. */
    private function element(?\DOMNode $wezel): DOMElement
    {
        $this->assertInstanceOf(DOMElement::class, $wezel, 'Oczekiwany element HTML, a zapytanie zwróciło coś innego.');

        return $wezel;
    }
}
