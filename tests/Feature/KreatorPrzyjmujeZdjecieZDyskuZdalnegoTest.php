<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * Kreator przepisu przyjmuje zdjęcie, gdy dysk tymczasowy Livewire jest
 * zdalny (audyt A5-08).
 *
 * Na produkcji `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`, a
 * `TemporaryUploadedFile::getRealPath()` oddaje wtedy `storage->path()` —
 * dla dysku S3/R2 ścieżkę WZGLĘDNĄ w buckecie. `StoreUploadedImage` czytał
 * plik spod tej ścieżki i odrzucał każde zdjęcie.
 *
 * R2 w teście nie ma, więc dysk „udaje zdalny" dokładnie w tym jednym
 * miejscu, które tu się liczy: pliki leżą w katalogu, ale `path()` oddaje
 * ścieżkę bez prefiksu katalogu, tak jak adapter S3 bez `root`. Livewire
 * w testach podmienia dysk tymczasowy na lokalny, dlatego plik tymczasowy
 * budujemy wprost, bez `Livewire::test()`.
 */
class KreatorPrzyjmujeZdjecieZDyskuZdalnegoTest extends TestCase
{
    use RefreshDatabase;

    private string $katalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->katalog = sys_get_temp_dir().'/kuking-udawany-r2-'.bin2hex(random_bytes(4));

        Storage::extend('udawany-zdalny', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['katalog']);

            // `root` pusty: `path()` oddaje klucz obiektu, nie plik na dysku.
            return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => '']);
        });

        config([
            'filesystems.disks.udawany-r2' => ['driver' => 'udawany-zdalny', 'katalog' => $this->katalog],
            'livewire.temporary_file_upload.disk' => 'udawany-r2',
        ]);

        Storage::fake('testowy');
        Storage::fake('testowy-publiczny');
        config([
            'kuking.media.disk' => 'testowy',
            'kuking.media.public_disk' => 'testowy-publiczny',
        ]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->katalog);

        parent::tearDown();
    }

    private function plikNaDyskuZdalnym(): TemporaryUploadedFile
    {
        $zdjecie = UploadedFile::fake()->image('obiad.jpg', 1200, 900);
        Storage::disk('udawany-r2')->put('livewire-tmp/abc123-meta.jpg', $zdjecie->get());

        return new TemporaryUploadedFile('abc123-meta.jpg', 'udawany-r2');
    }

    public function test_dysk_testowy_naprawde_nie_daje_lokalnej_sciezki(): void
    {
        // Kontrola warunku: bez tego test niżej niczego by nie dowodził.
        $plik = $this->plikNaDyskuZdalnym();

        $this->assertTrue($plik->exists());
        $this->assertSame('livewire-tmp/abc123-meta.jpg', $plik->getRealPath());
        $this->assertFileDoesNotExist($plik->getRealPath());
    }

    public function test_zdjecie_z_dysku_zdalnego_jest_przyjete(): void
    {
        $media = app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: $this->plikNaDyskuZdalnym(),
        );

        $this->assertSame(Media::STATUS_PENDING, $media->status);
        $this->assertSame(1200, $media->width);
        $this->assertSame(900, $media->height);
        $this->assertSame('image/jpeg', $media->mime_type);
        Storage::disk('testowy')->assertExists($media->object_key);
    }

    public function test_kopia_lokalna_nie_zostaje_w_katalogu_tymczasowym(): void
    {
        $przed = glob(sys_get_temp_dir().'/kuking-zdjecie-*') ?: [];

        app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: $this->plikNaDyskuZdalnym(),
        );

        $this->assertSame($przed, glob(sys_get_temp_dir().'/kuking-zdjecie-*') ?: []);
    }

    public function test_plik_ktory_nie_jest_zdjeciem_dalej_jest_odrzucony(): void
    {
        // Kopia lokalna nie może niczego przepuścić: zawartość dalej
        // sprawdza `StoreUploadedImage`.
        Storage::disk('udawany-r2')->put('livewire-tmp/zly-meta.jpg', 'to nie jest zdjęcie');

        $this->expectException(BladDlaCzlowieka::class);

        app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: new TemporaryUploadedFile('zly-meta.jpg', 'udawany-r2'),
        );
    }
}
