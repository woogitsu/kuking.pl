<?php

declare(strict_types=1);

namespace Tests\Dr;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Post;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\TestCase;

/**
 * Pomiar wariantu DR, NIE decyzja o wdrożeniu. MinIO nie emuluje R2 Bucket Locks:
 * chroni wersje S3. Wynik nie potwierdza uprawnień ani retencji na Cloudflare.
 * Uruchamiaj wyłącznie przez scripts/proba-dr-zdjec.py na własnej bazie.
 */
class DrZdjecMinioTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;

    private function client(string $role): S3Client
    {
        $keys = json_decode((string) getenv('DR_LOCAL_KEYS'), true, flags: JSON_THROW_ON_ERROR);

        return new S3Client([
            'version' => 'latest', 'region' => 'us-east-1',
            'endpoint' => getenv('DR_LOCAL_ENDPOINT'), 'use_path_style_endpoint' => true,
            'credentials' => $keys[$role], 'retries' => 0,
            'http' => ['connect_timeout' => 3, 'timeout' => 10],
        ]);
    }

    protected function setUp(): void
    {
        if (! preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', (string) getenv('DR_LOCAL_ENDPOINT'))
            || getenv('DB_PORT') !== '55439'
            || getenv('DB_DATABASE') !== 'kuking_flota_gpt-dr-zdjecia') {
            throw new \RuntimeException('Uruchom lokalny przyrząd DR na własnej bazie i buckecie.');
        }
        parent::setUp();
        Queue::fake();
        $keys = json_decode((string) getenv('DR_LOCAL_KEYS'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['dr-originals', 'dr-variants'] as $bucket) {
            config(['filesystems.disks.'.$bucket => [
                'driver' => 'r2', 'key' => $keys['app']['key'], 'secret' => $keys['app']['secret'],
                'endpoint' => getenv('DR_LOCAL_ENDPOINT'), 'region' => 'us-east-1',
                'bucket' => $bucket, 'use_path_style_endpoint' => true, 'throw' => true,
            ]]);
            Storage::forgetDisk($bucket);
        }
        config(['kuking.media.disk' => 'dr-originals', 'kuking.media.public_disk' => 'dr-variants']);
    }

    private function denied(callable $operation, string $label, bool $retention = false): void
    {
        try {
            $operation();
        } catch (S3Exception $exception) {
            fwrite(STDOUT, "\nODMOWA ".$label.': '.$exception->getStatusCode().' '.$exception->getAwsErrorCode().' '.$exception->getAwsErrorMessage()."\n");
            $this->assertSame($retention ? 400 : 403, $exception->getStatusCode(), $label);
            $this->assertSame($retention ? 'InvalidRequest' : 'AccessDenied', $exception->getAwsErrorCode(), $label);
            if ($retention) {
                $this->assertStringContainsString('WORM protected', $exception->getAwsErrorMessage());
            }

            return;
        }
        $this->fail('BRAK_ODMOWY: '.$label);
    }

    public function test_kopia_przezywa_delete_a_wpis_odzyskuje_prawdziwe_zdjecia(): void
    {
        $admin = $this->client('admin');
        $writer = $this->client('writer');
        $reader = $this->client('reader');
        $app = $this->client('app');
        // Kontrola dodatnia uprawnienia DELETE: ten sam token potrafi skasować niezablokowany obiekt.
        $control = ['Bucket' => 'dr-control', 'Key' => 'kontrola.jpg'];
        $writer->putObject($control + ['Body' => 'kontrola']);
        $writer->deleteObject($control);
        $this->assertFalse($admin->doesObjectExistV2('dr-control', 'kontrola.jpg'));

        $owner = $this->user('probadr');
        $manifest = [];
        $posts = [];
        $started = microtime(true);
        for ($i = 0; $i < 3; $i++) {
            $media = app(StoreUploadedImage::class)->handle($owner, UploadedFile::fake()->image('obiad.jpg', 800 + $i, 600));
            (new ProcessUploadedImage($media->getKey()))->handle();
            $media->refresh();
            $post = Post::factory()->create(['author_id' => $owner->getKey()]);
            $post->media()->attach($media->getKey());
            $posts[] = [$post, $media];
            $objects = [[$media->disk, $media->object_key]];
            $this->assertCount(4, $media->metadata['variants']);
            foreach ($media->metadata['variants'] as $variant) {
                $objects[] = [$media->variants_disk, $variant['key']];
            }
            foreach ($objects as [$bucket, $key]) {
                $body = (string) $app->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];
                $backupKey = 'snapshot/'.$bucket.'/'.$key;
                $version = $writer->putObject(['Bucket' => 'dr-backup', 'Key' => $backupKey, 'Body' => $body])['VersionId'];
                $copy = (string) $reader->getObject(['Bucket' => 'dr-backup', 'Key' => $backupKey, 'VersionId' => $version])['Body'];
                $this->assertSame(strlen($body), strlen($copy));
                $this->assertSame(hash('sha256', $body), hash('sha256', $copy));
                $manifest[] = compact('bucket', 'key', 'backupKey', 'version') + [
                    'sha256' => hash('sha256', $body), 'bytes' => strlen($body),
                ];
                if ($media->disk === $bucket) {
                    $this->assertSame($media->checksum_sha256, hash('sha256', $body));
                }
            }
        }
        $this->assertCount(15, $manifest, 'Próba musi objąć trzy zdjęcia i wszystkie cztery warianty.');
        $first = $manifest[0];
        $target = ['Bucket' => 'dr-backup', 'Key' => $first['backupKey'], 'VersionId' => $first['version']];
        $this->denied(fn () => $app->getObject($target), 'aplikacja czyta kopię');
        $this->denied(fn () => $app->deleteObject($target), 'aplikacja kasuje kopię');
        $this->denied(fn () => $app->putObject(['Bucket' => 'dr-backup', 'Key' => 'obcy', 'Body' => 'obcy']), 'aplikacja zapisuje kopię');
        $this->denied(fn () => $reader->deleteObject($target), 'czytelnik kasuje kopię');
        $this->denied(fn () => $reader->putObject(['Bucket' => 'dr-backup', 'Key' => 'obcy', 'Body' => 'obcy']), 'czytelnik zapisuje kopię');
        $this->denied(fn () => $writer->deleteObject(['Bucket' => $first['bucket'], 'Key' => $first['key']]), 'pisarz kasuje źródło');
        $this->denied(fn () => $writer->deleteObject($target), 'pisarz kasuje chronioną wersję', retention: true);
        $this->denied(fn () => $admin->deleteObject($target), 'administrator kasuje wersję COMPLIANCE', retention: true);
        $this->denied(fn () => $writer->putObjectLockConfiguration([
            'Bucket' => 'dr-backup', 'ObjectLockConfiguration' => ['ObjectLockEnabled' => 'Enabled'],
        ]), 'pisarz zmienia retencję');

        // S3 dopuszcza nową wersję oraz delete marker. Odtwarzamy KONKRETNĄ wersję z manifestu.
        $writer->putObject(['Bucket' => 'dr-backup', 'Key' => $first['backupKey'], 'Body' => 'zepsute']);
        $writer->deleteObject(['Bucket' => 'dr-backup', 'Key' => $first['backupKey']]);
        $this->assertSame($first['sha256'], hash('sha256', (string) $reader->getObject($target)['Body']));
        $copyFinished = microtime(true);
        foreach ($manifest as $entry) {
            $app->deleteObject(['Bucket' => $entry['bucket'], 'Key' => $entry['key']]);
            $this->assertFalse($app->doesObjectExistV2($entry['bucket'], $entry['key']));
        }
        $restoreStarted = microtime(true);
        foreach ($manifest as $entry) {
            $body = (string) $reader->getObject([
                'Bucket' => 'dr-backup', 'Key' => $entry['backupKey'], 'VersionId' => $entry['version'],
            ])['Body'];
            $this->assertSame($entry['bytes'], strlen($body));
            $this->assertSame($entry['sha256'], hash('sha256', $body), 'NIEZGODNA_SUMA_KOPII');
            $app->putObject(['Bucket' => $entry['bucket'], 'Key' => $entry['key'], 'Body' => $body]);
            $restored = (string) $app->getObject(['Bucket' => $entry['bucket'], 'Key' => $entry['key']])['Body'];
            $this->assertSame($entry['sha256'], hash('sha256', $restored));
            $this->assertNotFalse(getimagesizefromstring($restored));
        }
        foreach ($posts as [$post, $media]) {
            $this->get(route('posts.show', $post))->assertOk()->assertSee($media->url('feed'), false);
            foreach (array_keys($media->metadata['variants']) as $variant) {
                $response = $this->get($media->url($variant))->assertRedirect();
                $url = $response->headers->get('Location');
                $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
                $download = Http::timeout(10)->get($url);
                $this->assertSame(200, $download->status());
                $this->assertNotFalse(getimagesizefromstring($download->body()));
            }
        }
        // Usunięty wpis nie wraca do produktu tylko dlatego, że pliki zostały odtworzone.
        [$deletedPost, $deletedMedia] = $posts[0];
        $deletedPost->delete();
        $this->get(route('posts.show', $deletedPost))->assertNotFound();
        $this->get($deletedMedia->url('feed'))->assertNotFound();
        fwrite(STDOUT, "\nDR_WYNIK ".json_encode([
            'obiekty' => count($manifest), 'wpisy' => count($posts),
            'bajty' => array_sum(array_column($manifest, 'bytes')),
            'przygotowanie_i_kopia_s' => round($copyFinished - $started, 3),
            'wiek_kopii_przy_awarii_s' => round($restoreStarted - $copyFinished, 3),
            'odtworzenie_i_weryfikacja_http_s' => round(microtime(true) - $restoreStarted, 3),
            'odmowy_403' => 7, 'odmowy_400_worm' => 2, 'produkcja' => 'niebadana',
        ], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_pomiar_gps_przed_zapisem_i_surowego_put_do_kwarantanny(): void
    {
        $raw = $this->jpegZGps();
        $this->assertArrayHasKey('GPSLatitude', $this->exif($raw));
        $file = UploadedFile::fake()->createWithContent('kontrola.jpg', $raw);
        $media = app(StoreUploadedImage::class)->handle($this->user('probagps'), $file);
        $stored = Storage::disk('dr-originals')->get($media->object_key);
        $this->assertArrayNotHasKey('GPSLatitude', $this->exif($stored));
        $this->assertSame('TestPhone', $this->exif($stored)['Make'] ?? null);

        // Kontrprzykład propozycji #602, bez wdrażania endpointu ani decyzji produktowej.
        $target = ['Bucket' => 'dr-control', 'Key' => 'quarantine/kontrola.jpg'];
        $this->client('writer')->putObject($target + ['Body' => $raw]);
        $quarantined = (string) $this->client('writer')->getObject($target)['Body'];
        $this->assertSame($raw, $quarantined);
        $this->assertArrayHasKey('GPSLatitude', $this->exif($quarantined));
        fwrite(STDOUT, "\nGPS_WYNIK: obecna droga usuwa GPS przed MinIO; surowy PUT zachowuje GPS.\n");
    }
}
