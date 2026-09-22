<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BladZdjeciaPodazaZaKrokiemTest extends TestCase
{
    use RefreshDatabase;

    public static function moves(): array
    {
        return [['moveStepUp', 1, 0], ['moveStepDown', 1, 2], ['removeStep', 0, 0], ['removeStep', 1, null]];
    }

    #[DataProvider('moves')]
    public function test_odmowa_zdjecia_zostaje_przy_swoim_kroku(string $action, int $index, ?int $target): void
    {
        config(['kuking.media.max_bytes' => 1]);
        $component = Livewire::actingAs($this->user())->test('recipe-wizard')
            ->set('title', 'Zupa z koperkiem')
            ->set('steps.0.instruction', 'Krok pierwszy.')
            ->set('steps.1.instruction', 'Krok ze złym zdjęciem.')
            ->set('steps.2.instruction', 'Krok ostatni.')
            ->set('step', 3)
            ->set('steps.1.photo', UploadedFile::fake()->image('krok.jpg'))
            ->assertHasErrors('steps.1.photo')
            ->call($action, $index);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($dom);
        if ($target === null) {
            $component->assertHasNoErrors();
            $this->assertStringNotContainsString('wybierz mniejsze zdjęcie.', $component->html());
        } else {
            $component->assertHasErrors("steps.$target.photo");
            $row = $xpath->query("//div[@class='wizard-row'][.//textarea[@id='f-steps-$target-instruction']]")->item(0);
            $this->assertNotNull($row);
            $this->assertStringContainsString('Krok ze złym zdjęciem.', $row->textContent);
            $this->assertStringContainsString('wybierz mniejsze zdjęcie.', $row->textContent);
            $this->assertSame(1, $xpath->query("//a[@href='#f-steps-$target-photo']")->length);
            $this->assertSame(1, $xpath->query("//div[@class='wizard-row']//*[contains(@class,'has-error')]")->length);
        }
    }
}
