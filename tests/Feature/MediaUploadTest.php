<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Pipeline zdjęć. Zasada: nie ufamy niczemu, co przyszło od klienta.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_plik_z_falszywym_rozszerzeniem_jest_odrzucany(): void
    {
        $basia = $this->user();

        // Plik tekstowy nazwany .jpg. Rozszerzenie kłamie, magic bytes nie.
        $plik = UploadedFile::fake()->createWithContent('obiad.jpg', 'to nie jest obraz');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie wygląda na zdjęcie');

        app(StoreUploadedImage::class)->handle($basia, $plik);
    }

    public function test_zbyt_duzy_plik_daje_komunikat_z_konkretnym_limitem(): void
    {
        $basia = $this->user();
        config(['kuking.media.max_bytes' => 1024]);

        $plik = UploadedFile::fake()->image('obiad.jpg', 800, 600)->size(5000);

        try {
            app(StoreUploadedImage::class)->handle($basia, $plik);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException $e) {
            // Nie „422 Unprocessable Entity”, a informacja, co zrobić.
            $this->assertStringContainsString('MB', $e->getMessage());
            $this->assertStringContainsString('mniejsze zdjęcie', $e->getMessage());
        }
    }

    public function test_zdjecie_o_zbyt_duzych_wymiarach_jest_odrzucane(): void
    {
        $basia = $this->user();
        config(['kuking.media.max_megapixels' => 1]);

        $plik = UploadedFile::fake()->image('ogromne.jpg', 3000, 3000);

        $this->expectExceptionMessage('za duże wymiary');

        app(StoreUploadedImage::class)->handle($basia, $plik);
    }

    public function test_nazwa_pliku_od_uzytkownika_nie_trafia_do_sciezki(): void
    {
        $basia = $this->user();

        $plik = UploadedFile::fake()->image('../../etc/passwd.jpg', 400, 300);

        $media = app(StoreUploadedImage::class)->handle($basia, $plik);

        $this->assertStringNotContainsString('passwd', $media->object_key);
        $this->assertStringNotContainsString('..', $media->object_key);

        // Prefiks to `incoming/`, nie `media/`: oryginał zachowuje EXIF z GPS,
        // więc leży w prywatnej części storage, a publikowane są wyłącznie
        // przekodowane warianty pod `media/` (audyt A02, OryginalZdjeciaTest).
        //
        // Sam test dotyczy czego innego — tego, że nazwa pliku od użytkownika
        // nigdy nie trafia do ścieżki. Asercja na prefiksie jest tu po to, żeby
        // klucz był w ogóle tam, gdzie ma być, więc aktualizuję ją zamiast
        // usuwać.
        $this->assertStringStartsWith('incoming/'.$basia->getKey().'/', $media->object_key);
    }

    public function test_nowe_zdjecie_nie_jest_gotowe_do_pokazania_przed_przetworzeniem(): void
    {
        // Wstrzymujemy kolejkę, żeby sprawdzić stan DOKŁADNIE po przyjęciu
        // pliku. Na produkcji przetworzenie idzie w tle, więc ten moment
        // realnie istnieje i trwa kilka sekund.
        Queue::fake();

        $basia = $this->user();

        $media = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 800, 600),
        );

        // Do momentu przetworzenia (m.in. zdjęcia EXIF/GPS) zdjęcie nie
        // pojawia się w żadnym widoku.
        $this->assertSame(Media::STATUS_PENDING, $media->fresh()->status);
        $this->assertFalse($media->fresh()->isReady());

        Queue::assertPushed(ProcessUploadedImage::class);
    }

    public function test_przetworzenie_generuje_warianty_i_oznacza_zdjecie_jako_gotowe(): void
    {
        $basia = $this->user();

        $media = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 2000, 1500),
        );

        // Kolejka w testach jest synchroniczna, ale wołamy job jawnie,
        // żeby test opisywał dokładnie tę operację.
        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertTrue($media->metadata['exif_stripped']);
        $this->assertArrayHasKey('thumb', $media->metadata['variants']);
        $this->assertArrayHasKey('feed', $media->metadata['variants']);
        $this->assertArrayHasKey('large', $media->metadata['variants']);

        // scaleDown nigdy nie powiększa — wariant „large” nie może być
        // większy niż oryginał.
        $this->assertLessThanOrEqual(1600, $media->metadata['variants']['large']['width']);
        $this->assertLessThanOrEqual(320, $media->metadata['variants']['thumb']['width']);
    }
}
