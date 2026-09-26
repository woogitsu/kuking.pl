<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * „Pokaż hasło” przy każdym polu hasła z `x-field` (issue #948).
 *
 * Przycisk jest w HTML-u ukryty (`hidden`) i odsłania go dopiero
 * `resources/js/pokaz-haslo.js` — bez skryptu nie ma martwego przycisku,
 * a pole zostaje zamaskowane z niezmienionym `autocomplete`.
 */
final class PokazHasloTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{input: \DOMElement, button: \DOMElement}> */
    private function paryPoleIPrzycisk(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $pary = [];

        foreach (self::elementyDom($xpath->query('//input[@type="password"]')) as $input) {
            $buttons = $xpath->query('//button[@data-pokaz-haslo][@aria-controls="'.$input->getAttribute('id').'"]');
            $this->assertSame(1, $buttons->length, 'Pole '.$input->getAttribute('id').' musi mieć dokładnie jeden przełącznik.');
            $pary[] = ['input' => $input, 'button' => self::elementDom($buttons->item(0))];
        }

        return $pary;
    }

    private function sprawdzPrzycisk(\DOMElement $button): void
    {
        $this->assertSame('button', $button->getAttribute('type'), 'Przełącznik nie może wysyłać formularza.');
        $this->assertTrue($button->hasAttribute('hidden'), 'Bez JavaScriptu przycisk ma być niewidoczny, żeby nie był martwy.');
        $this->assertSame('false', $button->getAttribute('aria-pressed'));
        $this->assertStringStartsWith('Pokaż hasło', trim($button->textContent));
        $this->assertStringContainsString('btn', $button->getAttribute('class'));
    }

    public function test_logowanie_ma_ukryty_przelacznik_a_pole_zostaje_zamaskowane(): void
    {
        $pary = $this->paryPoleIPrzycisk($this->get(route('login'))->assertOk()->getContent());

        $this->assertCount(1, $pary);
        $this->sprawdzPrzycisk($pary[0]['button']);
        $this->assertSame('current-password', $pary[0]['input']->getAttribute('autocomplete'));
        $this->assertSame('password', $pary[0]['input']->getAttribute('name'));
    }

    public function test_kilka_pol_na_ustawieniach_bezpieczenstwa_ma_osobne_przelaczniki_i_nazwy(): void
    {
        $html = $this->actingAs($this->user())->get(route('settings.security'))->assertOk()->getContent();
        $pary = $this->paryPoleIPrzycisk($html);

        $this->assertGreaterThanOrEqual(4, count($pary));
        $nazwy = [];
        foreach ($pary as $para) {
            $this->sprawdzPrzycisk($para['button']);
            $nazwy[] = preg_replace('/\s+/u', ' ', trim($para['button']->textContent));
        }
        $this->assertContains('Pokaż hasło: Obecne hasło', $nazwy);
        $this->assertContains('Pokaż hasło: Nowe hasło', $nazwy);
        $this->assertContains('Pokaż hasło: Powtórz nowe hasło', $nazwy);
        $this->assertSame(count($pary), count(array_unique(array_map(fn ($p) => $p['button']->getAttribute('aria-controls'), $pary))));
    }

    public function test_kontrola_dodatnia_zwykle_pole_nie_dostaje_przelacznika(): void
    {
        $this->withViewErrors([]);
        $html = Blade::render('<x-field name="email" label="E-mail" type="email" />');
        $this->assertStringNotContainsString('data-pokaz-haslo', $html);
        $this->assertStringContainsString('type="email"', $html);

        // Ten sam komponent z `type="password"` przełącznik dostaje — bez tego
        // brak `data-pokaz-haslo` wyżej niczego by nie dowodził.
        $html = Blade::render('<x-field name="password" label="Hasło" type="password" />');
        $this->assertStringContainsString('data-pokaz-haslo aria-controls="f-password"', $html);
    }
}
