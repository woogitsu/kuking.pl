<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #802, druga połowa: „Dodaj” czy „Zmień zdjęcie profilowe” na WŁASNYM
 * profilu — przy awatarze w nagłówku i w skrótach prawej szyny.
 *
 * Ekran ustawień pyta już `Profile::zdjecieDoPokazania()` (PR #1191,
 * `OpisAwataraWProfiluTest`). Profil pytał dalej `avatar?->isReady()`, więc
 * przy `pending` z gotowym podglądem obok widocznej twarzy stało „Dodaj
 * zdjęcie profilowe”, a przy `ready` bez wariantu — „Zmień” pod pierwszą
 * literą imienia. Etykieta ma mówić o tym samym, co pokazuje obrazek.
 */
class PrzyciskZdjeciaNaWlasnymProfiluTest extends TestCase
{
    use RefreshDatabase;

    public static function stany(): array
    {
        return [
            'pending z podglądem — twarz widać' => ['pending', true, true],
            'gotowe z podglądem' => ['ready', true, true],
            'ready bez wariantu — twarzy nie widać' => ['ready', false, false],
            'pending bez wariantu' => ['pending', false, false],
            'usunięte' => ['deleted', true, false],
            'brak zdjęcia' => [null, false, false],
        ];
    }

    #[DataProvider('stany')]
    public function test_etykieta_przy_awatarze_i_w_szynie_zgadza_sie_z_obrazkiem(?string $stan, bool $wariant, bool $widac): void
    {
        $basia = $this->profilZeZdjeciem($stan, $wariant);

        $xpath = $this->xpathProfilu($basia);
        $oczekiwana = $widac ? 'Zmień zdjęcie profilowe' : 'Dodaj zdjęcie profilowe';
        $bledna = $widac ? 'Dodaj zdjęcie profilowe' : 'Zmień zdjęcie profilowe';

        $naglowek = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " profil-awatar-zmiana ")]');
        $this->assertCount(1, $naglowek, 'Brak odnośnika do zdjęcia przy awatarze w nagłówku własnego profilu.');
        $this->assertSame($widac ? 1 : 0, $xpath->query('.//img', $naglowek->item(0))->length, 'Obrazek w nagłówku nie odpowiada stanowi zdjęcia.');
        $this->assertStringContainsString($oczekiwana, $naglowek->item(0)->textContent);
        $this->assertStringNotContainsString($bledna, $naglowek->item(0)->textContent);

        $skrot = $xpath->query('//section[@aria-labelledby="szyna-skroty"]//a[contains(@class,"szyna-pozycja-link") and @href="'.route('settings.avatar').'"]');
        $this->assertCount(1, $skrot, 'Brak skrótu do zdjęcia profilowego w prawej szynie.');
        $this->assertStringContainsString($oczekiwana, $skrot->item(0)->textContent);
        $this->assertStringNotContainsString($bledna, $skrot->item(0)->textContent);
    }

    private function profilZeZdjeciem(?string $stan, bool $wariant): User
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        if ($stan !== null) {
            $zdjecie = Media::factory()->create([
                'owner_id' => $basia->id,
                'status' => $stan,
                'variants_disk' => 'public',
                'metadata' => ['variants' => $wariant ? ['podglad' => ['key' => 'media/proba.webp']] : []],
            ]);

            if ($wariant) {
                $obraz = imagecreatetruecolor(20, 20);
                ob_start();
                imagewebp($obraz);
                Storage::disk('public')->put('media/proba.webp', ob_get_clean());
                imagedestroy($obraz);
            }

            $basia->profile()->update(['avatar_media_id' => $zdjecie->id]);
        }

        return $basia->fresh();
    }

    private function xpathProfilu(User $wlasciciel): DOMXPath
    {
        $html = $this->actingAs($wlasciciel)
            ->get(route('profile.show', $wlasciciel->profile->username))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }
}
