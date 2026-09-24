<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpisAwataraWProfiluTest extends TestCase
{
    use RefreshDatabase;

    public static function states(): array
    {
        return [
            'podglad pending' => ['pending', true, true, 'Możesz zmienić lub usunąć'],
            'gotowe' => ['ready', true, true, 'Możesz zmienić lub usunąć'],
            'przygotowanie' => ['pending', false, false, 'Nie możemy teraz pokazać zdjęcia'],
            'ready bez wariantu' => ['ready', false, false, 'Nie możemy teraz pokazać zdjęcia'],
            'usuniete' => ['deleted', true, false, 'Nie masz jeszcze zdjęcia'],
            'brak' => [null, false, false, 'Nie masz jeszcze zdjęcia'],
        ];
    }

    public function test_brak_pliku_nie_daje_obietnicy_widocznosci(): void
    {
        Storage::fake('public');
        $user = $this->user('basia');
        $photo = Media::factory()->create(['owner_id' => $user->id, 'variants_disk' => 'public']);
        $user->profile()->update(['avatar_media_id' => $photo->id]);
        foreach ($photo->metadata['variants'] as $variant) {
            Storage::disk('public')->assertMissing($variant['key']);
        }
        $html = $this->actingAs($user->fresh())->get(route('settings.profile'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $section = (new DOMXPath($dom))->query('//section[contains(@class,"zdjecie-profilowe-skrot")]');
        $this->assertCount(1, $section);
        $this->assertStringContainsString('Zmień zdjęcie profilowe', $section->item(0)->textContent);
        $this->assertStringNotContainsString('Twoje zdjęcie widać', $section->item(0)->textContent, 'BRAK_PLIKU_OBIETNICA_WIDOCZNOSCI');
    }

    #[DataProvider('states')]
    public function test_opis_i_przycisk_sa_zgodne_z_obrazkiem(?string $state, bool $variant, bool $visible, string $message): void
    {
        Storage::fake('public');
        $user = $this->user('basia');
        if ($state !== null) {
            $photo = Media::factory()->create([
                'owner_id' => $user->id, 'status' => $state, 'variants_disk' => 'public',
                'metadata' => ['variants' => $variant ? ['podglad' => ['key' => 'media/proba.webp']] : []],
            ]);
            if ($variant) {
                $image = imagecreatetruecolor(20, 20);
                ob_start();
                imagewebp($image);
                Storage::disk('public')->put('media/proba.webp', ob_get_clean());
                imagedestroy($image);
            }
            $user->profile()->update(['avatar_media_id' => $photo->id]);
        }
        $html = $this->actingAs($user->fresh())->get(route('settings.profile'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $section = $xpath->query('//section[contains(@class,"zdjecie-profilowe-skrot")]');
        $this->assertCount(1, $section);
        $node = $section->item(0);
        $this->assertSame($visible ? 1 : 0, $xpath->query('.//img', $node)->length);
        $this->assertStringContainsString($message, $node->textContent);
        $this->assertStringContainsString($visible ? 'Zmień zdjęcie profilowe' : 'Dodaj zdjęcie profilowe', $node->textContent);
        if ($visible) {
            $this->assertStringNotContainsString('Nie masz jeszcze zdjęcia', $node->textContent);
        }
    }
}
