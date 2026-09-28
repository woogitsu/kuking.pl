<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KompozycjaZainteresowanTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_kafle_renderuja_wszystkie_promowane_tagi_jako_natywny_formularz(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $name = $i === 7 ? 'Domowe przepisy rodzinne' : 'Temat '.$i;
            $tag = Tag::create(['slug' => 'temat-'.$i, 'name' => $name, 'normalized_name' => mb_strtolower($name)]);
            TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $i]);
            $ids[] = (string) $tag->getKey();
        }
        Tag::create(['slug' => 'bez-promocji', 'name' => 'Bez promocji', 'normalized_name' => 'bez promocji']);
        $html = (string) $this->actingAs($this->user('wybor'))->get(route('onboarding.interests'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $scope = '//section[@aria-labelledby="onboarding-title"]';
        $form = $scope.'/form[@action="'.route('onboarding.interests').'"]';
        $this->assertSame(1, $xpath->query($form.'[@method="POST"]')->length);
        $this->assertSame(1, $xpath->query($form.'/input[@name="_token"]')->length);
        $inputs = $xpath->query($form.'//fieldset//label[contains(@class,"onboarding-interest-tile")]/input[@type="checkbox" and @name="tags[]"]');
        $actual = [];
        foreach (self::elementyDom($inputs) as $input) {
            $actual[] = $input->getAttribute('value');
            $this->assertSame(1, $xpath->query('following-sibling::span[contains(@class,"onboarding-interest-name")]', $input)->length);
        }
        $this->assertSame($ids, $actual, 'Kafle muszą zachować całą listę i kolejność prawdziwych tagów.');
        $this->assertSame(1, $xpath->query($form.'//button[@type="submit"]')->length);
        $this->assertSame(1, $xpath->query($form.'//a[@href="'.route('onboarding.people').'" and normalize-space(.)="Pomiń ten krok"]')->length);
        $this->assertStringNotContainsString('żeby nie była pusta', $xpath->query($scope)->item(0)->textContent);
    }

    public function test_puste_zainteresowania_maja_droge_dalej_bez_pozornych_kafli(): void
    {
        $html = (string) $this->actingAs($this->user('pusta'))->get(route('onboarding.interests'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $scope = '//section[@aria-labelledby="onboarding-title"]';
        $this->assertSame(0, $xpath->query($scope.'//input[@name="tags[]"]')->length);
        $this->assertSame(0, $xpath->query($scope.'//form')->length);
        $this->assertSame(1, $xpath->query($scope.'//a[@href="'.route('onboarding.people').'" and normalize-space(.)="Dalej"]')->length);
        $text = $xpath->query($scope)->item(0)->textContent;
        $this->assertStringContainsString('Krok 1 z 3', $text);
        $this->assertStringContainsString('konto już działa', $text);
        $this->assertStringContainsString('opcjonalne', $text);
    }
}
