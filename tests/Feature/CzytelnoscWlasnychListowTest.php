<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\Support\WlasneListyMarki;
use Tests\TestCase;

class CzytelnoscWlasnychListowTest extends TestCase
{
    public function test_kazdy_tekst_we_wlasnych_listach_ma_co_najmniej_18_px(): void
    {
        $listy = WlasneListyMarki::render();
        $this->assertCount(11, $listy);
        foreach ($listy as $nazwa => $html) {
            $xpath = $this->dom($html);
            foreach ($xpath->query('//body//*[text()[normalize-space()]]') as $element) {
                $this->assertGreaterThanOrEqual(18, $this->rozmiar($element), $nazwa.': '.trim($element->textContent));
            }
        }
    }

    public function test_linki_zadaniowe_digestu_maja_powiekszony_cel_bez_zmiany_adresu(): void
    {
        $xpath = $this->dom(WlasneListyMarki::render()['podsumowanie-tygodnia']);
        $linki = $xpath->query('//a');
        $this->assertCount(3, $linki);
        foreach ($linki as $link) {
            $styl = $this->styl($link);
            $this->assertSame('inline-block', $styl['display'] ?? null);
            [$gora, $dol] = $this->paddingPionowy($link);
            $naturalna = $gora + $dol + $this->interlinia($link);
            $minimum = isset($styl['min-height']) ? $this->piksele($styl['min-height']) : 0;
            if (($styl['box-sizing'] ?? 'content-box') !== 'border-box') {
                $minimum += $gora + $dol;
            }
            $this->assertGreaterThanOrEqual(48, max($minimum, $naturalna), trim($link->textContent));
        }
        $this->assertStringEndsWith('/ugotowane/12345678-1234-4234-8234-123456789abd', self::elementDom($linki->item(0))->getAttribute('href'));
        $this->assertStringEndsWith('/wpisy/12345678-1234-4234-8234-123456789abe', self::elementDom($linki->item(1))->getAttribute('href'));
        $this->assertSame('https://kuking.test/podsumowanie/wypisz/test?signature='.str_repeat('c', 64), self::elementDom($linki->item(2))->getAttribute('href'));
    }

    public function test_termin_eksportu_podaje_godzine_w_polskiej_strefie(): void
    {
        $xpath = $this->dom(WlasneListyMarki::render()['data-export-ready']);
        $termin = $xpath->query('//p[contains(., "Link działa do")]/strong')->item(0);
        $this->assertInstanceOf(DOMElement::class, $termin);
        $this->assertSame('20 września 2026, 12:37', $termin->textContent);
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }

    private function rozmiar(DOMElement $element): float
    {
        for ($rodzic = $element; $rodzic instanceof DOMElement; $rodzic = $rodzic->parentNode) {
            $styl = $this->styl($rodzic);
            if (isset($styl['font-size'])) {
                $this->assertMatchesRegularExpression('/^\d+(?:\.\d+)?px$/', $styl['font-size']);

                return (float) $styl['font-size'];
            }
        }

        return 16;
    }

    private function interlinia(DOMElement $element): float
    {
        for ($rodzic = $element; $rodzic instanceof DOMElement; $rodzic = $rodzic->parentNode) {
            $wartosc = $this->styl($rodzic)['line-height'] ?? null;
            if ($wartosc === null || $wartosc === 'inherit') {
                continue;
            }
            if (is_numeric($wartosc)) {
                // Liczba dziedziczy się jako mnożnik czcionki odbiorcy.
                return (float) $wartosc * $this->rozmiar($element);
            }
            if (preg_match('/^(\d+(?:\.\d+)?)(em|%)$/', $wartosc, $czesci)) {
                // Długość jest obliczana przy rodzicu i dziedziczona już w px.
                return (float) $czesci[1] * $this->rozmiar($rodzic) / ($czesci[2] === '%' ? 100 : 1);
            }

            return $this->piksele($wartosc);
        }
        $this->fail('Brak jawnej interlinii: jej wysokość trzeba zmierzyć w przeglądarce.');
    }

    private function paddingPionowy(DOMElement $element): array
    {
        $gora = $dol = 0;
        // Kolejność deklaracji ma znaczenie: longhand może nadpisać shorthand i odwrotnie.
        foreach (explode(';', $element->getAttribute('style')) as $regula) {
            if (! str_contains($regula, ':')) {
                continue;
            }
            [$nazwa, $wartosc] = array_map('trim', explode(':', $regula, 2));
            if ($nazwa === 'padding') {
                $wartosci = preg_split('/\s+/', $wartosc);
                $this->assertGreaterThanOrEqual(1, count($wartosci));
                $this->assertLessThanOrEqual(4, count($wartosci));
                $gora = $this->piksele($wartosci[0]);
                $dol = $this->piksele($wartosci[2] ?? $wartosci[0]);
            } elseif ($nazwa === 'padding-top') {
                $gora = $this->piksele($wartosc);
            } elseif ($nazwa === 'padding-bottom') {
                $dol = $this->piksele($wartosc);
            }
        }

        return [$gora, $dol];
    }

    private function piksele(string $wartosc): float
    {
        $this->assertMatchesRegularExpression('/^(?:0|\d+(?:\.\d+)?px)$/', $wartosc);

        return (float) $wartosc;
    }

    private function styl(DOMElement $element): array
    {
        $styl = [];
        foreach (explode(';', $element->getAttribute('style')) as $regula) {
            if (str_contains($regula, ':')) {
                [$klucz, $wartosc] = explode(':', $regula, 2);
                $styl[trim($klucz)] = trim($wartosc);
            }
        }

        return $styl;
    }
}
