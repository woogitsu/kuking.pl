<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Oryginał zdjęcia nie może być publicznie osiągalny (audyt A02).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Warianty publikowane na stronie powstają przez przekodowanie do WebP, więc
 * EXIF w nich nie ma. ORYGINAŁ zachowuje go w całości — łącznie ze
 * współrzędnymi GPS, czyli adresem kuchni użytkownika.
 *
 * Oryginał lądował pod `media/` jako `public`, a klucz wariantu powstawał
 * z niego przez odcięcie rozszerzenia:
 *
 *     oryginał:  media/{id}/2026/09/{uuid}.jpg          ← public, z GPS
 *     wariant:   media/{id}/2026/09/{uuid}_feed.webp    ← publikowany
 *
 * Znając adres miniatury, wystarczyło odciąć `_feed.webp` i dopisać `.jpg`.
 * Nic tego adresu nie publikowało — ale „nie linkujemy" nie jest
 * zabezpieczeniem, tylko nadzieją.
 *
 * `DEPLOYMENT_RUNBOOK.md` stawia sprawę jasno: „Punkt 15 jest niepodlegający
 * negocjacji. Zdjęcie z kuchni zawiera współrzędne domu użytkownika."
 *
 * DLACZEGO ORYGINAŁ ZOSTAJE, A NIE JEST KASOWANY
 * Eksport danych (RODO art. 20) ma oddać człowiekowi jego własne zdjęcie,
 * a nie zmniejszoną kopię — `GenerateUserExport` czyta właśnie `object_key`.
 * Aplikacja sięga po niego po stronie serwera, więc prywatny dostęp niczego
 * nie psuje.
 */
class OryginalZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    private function wgraj(): Media
    {
        Storage::fake('testowy');
        config(['kuking.media.disk' => 'testowy']);

        return app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: UploadedFile::fake()->image('obiad.jpg', 1200, 900),
            altText: 'Rosół',
        );
    }

    public function test_oryginal_ladunek_w_prywatnym_prefiksie(): void
    {
        $media = $this->wgraj();

        $this->assertStringStartsWith(
            'incoming/',
            $media->object_key,
            'Oryginał trafił poza prywatny prefiks — a zawiera EXIF z GPS.',
        );
    }

    public function test_klucza_oryginalu_nie_da_sie_wyprowadzic_z_klucza_wariantu(): void
    {
        $media = $this->wgraj();

        app(ProcessUploadedImage::class, ['mediaId' => $media->getKey()])->handle();

        $media->refresh();
        $warianty = $media->metadata['variants'] ?? [];

        $this->assertNotEmpty($warianty, 'Nie powstały warianty — test sprawdza co innego, niż zakłada.');

        foreach ($warianty as $nazwa => $wariant) {
            // To jest DOKŁADNIE ta operacja, którą wykonałby ktoś, kto zna
            // publiczny adres miniatury: odetnij sufiks wariantu, dopisz
            // rozszerzenie oryginału.
            $zgadniety = preg_replace('/_[a-z]+\.webp$/', '', $wariant['key']).'.jpg';

            $this->assertNotSame(
                $media->object_key,
                $zgadniety,
                "Klucz oryginału daje się wyprowadzić z klucza wariantu „{$nazwa}”.",
            );

            $this->assertStringStartsWith(
                'media/',
                $wariant['key'],
                "Wariant „{$nazwa}” nie leży w publicznym prefiksie.",
            );
        }
    }

    public function test_oryginal_zostaje_dostepny_dla_eksportu_danych(): void
    {
        $media = $this->wgraj();

        app(ProcessUploadedImage::class, ['mediaId' => $media->getKey()])->handle();

        // RODO art. 20: człowiek dostaje SWOJE zdjęcie, nie zmniejszoną kopię.
        // Kasowanie oryginału „dla bezpieczeństwa" zabrałoby mu jego własne dane.
        Storage::disk('testowy')->assertExists($media->refresh()->object_key);
    }
}
