<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\TestCase;

/**
 * KAŻDY publiczny wariant zdjęcia jest bez metadanych (RODO, issue #2336).
 *
 * `OryginalTraciWspolrzedneGpsTest` pilnuje oryginału, który z założenia
 * ZACHOWUJE resztę EXIF-u (D-023), a same warianty — jedyne pliki
 * serwowane publicznie — nie miały własnego pomiaru. Migracja Intervention
 * na wersję 4 dodała do kodowania opcję `strip` (domyślnie wyłączoną), więc
 * pytanie „czy warianty są czyste" przestało wynikać z samego faktu
 * przekodowania i trzeba je zadać na bajtach.
 *
 * Oracle: oryginał w storage NADAL niesie producenta aparatu i datę
 * (inaczej brak tych danych w wariancie niczego by nie dowodził).
 */
class WariantyZdjeciaNieNiosaMetadanychTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;

    public function test_zaden_wariant_nie_niesie_exif_gps_producenta_ani_daty(): void
    {
        Storage::fake('testowy');
        config([
            'kuking.media.disk' => 'testowy',
            'kuking.media.public_disk' => 'testowy',
        ]);

        $sciezka = tempnam(sys_get_temp_dir(), 'meta').'.jpg';
        file_put_contents($sciezka, $this->jpegZGps());

        $media = app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true),
        );
        @unlink($sciezka);

        (new ProcessUploadedImage((string) $media->getKey()))->handle();
        $media = $media->fresh();
        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame(Media::STATUS_READY, $media->status);

        // ORACLE: oryginał zachowuje producenta i datę, tylko bez GPS.
        $oryginal = (string) Storage::disk('testowy')->get($media->object_key);
        $exifOryginalu = $this->exif($oryginal);
        $this->assertSame('TestPhone', $exifOryginalu['Make'] ?? null, 'Oryginał nie niesie już producenta — pomiar wariantów byłby pusty.');
        $this->assertArrayNotHasKey('GPSLatitude', $exifOryginalu);

        /** @var mixed $metadata */
        $metadata = $media->metadata;
        $this->assertIsArray($metadata);
        $warianty = $metadata['variants'] ?? null;
        $this->assertIsArray($warianty);
        $this->assertGreaterThanOrEqual(3, count($warianty), 'Powstały mniej niż trzy warianty.');

        foreach ($warianty as $nazwa => $wariant) {
            $this->assertIsArray($wariant);
            $bajty = (string) Storage::disk('testowy')->get((string) $wariant['key']);
            $this->assertNotSame('', $bajty, "Wariant $nazwa jest pusty.");
            $this->assertIsArray(@getimagesizefromstring($bajty), "Wariant $nazwa nie jest obrazem.");
            $this->assertSame('RIFF', substr($bajty, 0, 4), "Wariant $nazwa nie jest WebP.");

            foreach (['TestPhone', '2026:09:07', 'Exif', 'EXIF', 'XMP ', 'GPSL'] as $slad) {
                $this->assertStringNotContainsString($slad, $bajty, "Wariant $nazwa niesie ślad metadanych: $slad");
            }
        }
    }
}
