<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodpowiedziPrzepisuMajaNoweLinieTest extends TestCase
{
    use RefreshDatabase;

    public function test_podpowiedzi_renderowanego_formularza_pokazuja_wiersze_zamiast_encji(): void
    {
        $odpowiedz = $this->actingAs($this->user())->get(route('recipes.create'))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$odpowiedz->getContent());
        $xpath = new DOMXPath($dom);
        $oczekiwane = [
            'skladniki_tekst' => "1 kurczak, najlepiej zagrodowy\n2 marchewki\npietruszka\nsól do smaku",
            'przygotowanie_tekst' => "Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny.\n\nWrzuć warzywa i gotuj na małym ogniu trzy godziny.\n\nPosól na końcu.",
        ];

        foreach ($oczekiwane as $nazwa => $podpowiedz) {
            $pole = $xpath->query('//textarea[@name="'.$nazwa.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $pole, $nazwa);
            $this->assertSame($podpowiedz, str_replace("\r\n", "\n", $pole->getAttribute('placeholder')), $nazwa);
            $this->assertStringNotContainsString('&#10;', $pole->getAttribute('placeholder'), $nazwa);
        }
    }
}
