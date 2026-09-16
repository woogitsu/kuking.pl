<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\OrientacjaZdjecia;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use Tests\Support\LicznikDekodowanGd;
use Tests\TestCase;

class JednoDekodowanieZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wrapper funkcji GD nie może zostać w procesie reszty suity.
     * Metadata orientacji są wejściem joba: parser uploadu ma osobne testy.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_jedno_dekodowanie_zachowuje_bajty_wszystkich_wariantow(): void
    {
        require_once __DIR__.'/../Support/LicznikDekodowanGd.php';

        // Chroni także uruchomienia z vendor współdzielonym przez worktree.
        $this->assertSame(
            realpath(app_path('Jobs/ProcessUploadedImage.php')),
            realpath((string) (new ReflectionClass(ProcessUploadedImage::class))->getFileName()),
        );
        $this->assertTrue(function_exists('imageavif'), 'GD musi obsługiwać akceptowany format AVIF.');
        $this->assertTrue((bool) (gd_info()['AVIF Support'] ?? false));

        Storage::fake('oryginal601');
        Storage::fake('wariant601');
        $owner = $this->user();
        $edges = ['thumb' => 320, 'feed' => 960, 'large' => 1600];
        $this->assertSame($edges, config('kuking.media.variants'));
        $przypadki = 0;

        foreach (['jpeg', 'png', 'webp', 'avif'] as $format) {
            foreach ([[17, 11], [1201, 803]] as [$width, $height]) {
                $original = $this->obraz($format, $width, $height);
                foreach ([1, 6] as $orientation) {
                    $opis = "$format {$width}x{$height}, orientacja $orientation";
                    $key = "incoming/$format-$width-$orientation.$format";
                    Storage::disk('oryginal601')->put($key, $original);
                    $media = Media::create([
                        'owner_id' => $owner->getKey(),
                        'disk' => 'oryginal601',
                        'variants_disk' => 'wariant601',
                        'object_key' => $key,
                        'status' => Media::STATUS_PENDING,
                        'metadata' => ['exif_orientation' => $orientation],
                    ]);

                    // Dotychczasowy algorytm: każdy wariant bezpośrednio
                    // z ponownie zdekodowanego oryginału, nigdy z miniatury.
                    $expected = [];
                    $manager = ImageManager::gd(autoOrientation: false);
                    LicznikDekodowanGd::$liczba = 0;
                    foreach ($edges as $name => $edge) {
                        $image = $manager->read($original);
                        OrientacjaZdjecia::zastosuj($image, $orientation);
                        $image->scaleDown(width: $edge, height: $edge);
                        $expected[$name] = [
                            'bytes' => (string) $image->toWebp(quality: 82),
                            'width' => $image->width(),
                            'height' => $image->height(),
                        ];
                        unset($image);
                    }
                    // Kontrola dodatnia licznika: bez działającego wrappera
                    // zero wywołań nie może udawać udanej optymalizacji.
                    $this->assertSame(3, LicznikDekodowanGd::$liczba, $opis);

                    LicznikDekodowanGd::$liczba = 0;
                    (new ProcessUploadedImage($media->getKey()))->handle();
                    $this->assertSame(1, LicznikDekodowanGd::$liczba, $opis);
                    $media->refresh();
                    $this->assertSame(Media::STATUS_READY, $media->status, $opis);
                    $this->assertCount(3, $media->metadata['variants'], $opis);

                    foreach ($expected as $name => $variant) {
                        $actual = $media->wariant($name);
                        $this->assertNotNull($actual, "$opis, $name");
                        $bytes = Storage::disk('wariant601')->get($actual['key']);
                        $this->assertTrue($variant['bytes'] === $bytes, "$opis, $name: zmienione piksele lub kodowanie");
                        $this->assertSame($variant['width'], $actual['width'], $opis);
                        $this->assertSame($variant['height'], $actual['height'], $opis);
                        $this->assertSame(strlen($variant['bytes']), $actual['bytes'], $opis);
                    }
                    $przypadki++;
                }
            }
        }

        $this->assertSame(16, $przypadki);
    }

    /** JPEG ma kontrastowe pasy; pozostałe formaty także przezroczystość. */
    private function obraz(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 10, 30, 90, $format === 'jpeg' ? 0 : 127));
        imagefilledrectangle($image, 1, 1, intdiv($width, 2), $height - 2, imagecolorallocatealpha($image, 230, 30, 70, 0));
        imagefilledrectangle($image, intdiv($width, 2) + 1, 2, $width - 2, intdiv($height, 2), imagecolorallocatealpha($image, 20, 210, 90, $format === 'jpeg' ? 0 : 50));

        ob_start();
        try {
            $encoded = match ($format) {
                'jpeg' => imagejpeg($image, null, 93),
                'png' => imagepng($image),
                'webp' => imagewebp($image, null, 82),
                'avif' => imageavif($image, null, 80),
            };
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        $this->assertTrue($encoded);
        $this->assertNotSame('', $bytes);

        return $bytes;
    }
}
