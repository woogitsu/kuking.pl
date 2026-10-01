<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DostepnoscWyboruFormyTest extends TestCase
{
    use RefreshDatabase;

    public function test_blad_w_ustawieniach_jest_powiazany_z_kazdym_radiem_i_podsumowaniem(): void
    {
        $this->sprawdzBlad('settings.profile', 'settings.form_of_address', 'put');
    }

    public function test_blad_w_onboardingu_jest_powiazany_z_kazdym_radiem_i_podsumowaniem(): void
    {
        $this->sprawdzBlad('onboarding.done', 'onboarding.form_of_address', 'post');
    }

    public function test_bez_bledu_radio_wskazuje_tylko_pomoc_i_ma_natywna_obsluge_klawiatury(): void
    {
        $osoba = $this->user('basia');
        $html = $this->actingAs($osoba)->get(route('settings.profile'))->assertOk()->getContent();
        $dokument = new DOMDocument;
        $dokument->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dokument);
        $radia = $xpath->query('//form[@id="forma-zwracania"]//label[contains(concat(" ", normalize-space(@class), " "), " choice ")]/input[@type="radio" and @name="form_of_address"]');

        $this->assertCount(3, $radia);
        $this->assertCount(0, $xpath->query('//*[@id="f-form_of_address-error"]'));
        foreach ($radia as $radio) {
            $this->assertInstanceOf(DOMElement::class, $radio);
            $this->assertSame('forma-zwracania-pomoc', $radio->getAttribute('aria-describedby'));
            $this->assertFalse($radio->hasAttribute('aria-invalid'));
            $this->assertFalse($radio->hasAttribute('tabindex'), 'Natywne radio pozostaje w kolejności Tab.');
        }
    }

    private function sprawdzBlad(string $strona, string $zapis, string $metoda): void
    {
        $osoba = $this->user('basia');
        $this->actingAs($osoba)->get(route($strona))->assertOk();
        // Zmierzenie rzeczywistej drogi POST → walidacja → redirect → HTML.
        // Osobny GET po asercji redirectu gubił flash w sztucznej sesji testu.
        $html = $this->followingRedirects()->from(route($strona))
            ->{$metoda}(route($zapis), ['form_of_address' => 'spoza-listy'])
            ->assertOk()->getContent();
        $dokument = new DOMDocument;
        $dokument->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dokument);

        $pomoc = $xpath->query('//*[@id="forma-zwracania-pomoc"]');
        $blad = $xpath->query('//*[@id="f-form_of_address-error"]');
        $grupa = $xpath->query('//fieldset[@id="f-form_of_address"]');
        $radia = $xpath->query('//form[@id="forma-zwracania"]//input[@type="radio" and @name="form_of_address"]');
        $podsumowanie = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]');

        $this->assertCount(1, $pomoc);
        $this->assertCount(1, $blad);
        $this->assertCount(1, $grupa);
        $this->assertCount(3, $radia);
        $this->assertCount(1, $podsumowanie);
        $elementGrupy = $grupa->item(0);
        $elementBledu = $blad->item(0);
        $elementPodsumowania = $podsumowanie->item(0);
        $this->assertInstanceOf(DOMElement::class, $elementGrupy);
        $this->assertInstanceOf(DOMElement::class, $elementBledu);
        $this->assertInstanceOf(DOMElement::class, $elementPodsumowania);
        $this->assertSame('true', $elementGrupy->getAttribute('aria-invalid'));
        $this->assertSame('-1', $elementGrupy->getAttribute('tabindex'));
        $this->assertSame('-1', $elementPodsumowania->getAttribute('tabindex'));
        $this->assertCount(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]//a[@href="#f-form_of_address"]'));
        $this->assertStringContainsString('Zaznacz jedną z trzech odpowiedzi', $elementBledu->textContent);

        foreach ($radia as $radio) {
            $this->assertInstanceOf(DOMElement::class, $radio);
            $this->assertSame('true', $radio->getAttribute('aria-invalid'));
            $this->assertSame(
                ['forma-zwracania-pomoc', 'f-form_of_address-error'],
                preg_split('/\s+/', trim($radio->getAttribute('aria-describedby'))),
                'Każde radio musi wskazywać jednocześnie pomoc i błąd.',
            );
        }
    }
}
