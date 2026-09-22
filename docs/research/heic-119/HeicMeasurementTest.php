<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\ProductSignal;
use App\Support\RozpoznanieZdjecia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sonda ręczna #119: obserwuje obecną odmowę; nie rozstrzyga przyszłej obsługi HEIC. */
class HeicMeasurementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Sprawdzamy PRZED parent::setUp(), bo tam RefreshDatabase rusza bazę.
        if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55439'
            || getenv('DB_DATABASE') !== 'kuking_flota_gpt-heic-format') {
            throw new \RuntimeException('Sonda wymaga własnej bazy floty na 127.0.0.1:55439.');
        }

        parent::setUp();
    }

    public function test_measure_original_samples(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessUploadedImage::class]);
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
        $this->actingAs($this->user('pomiarheic'));
        foreach (['original.heic', 'live.heic', 'live.mov'] as $sample) {
            $path = base_path('.codex/heic-119/'.$sample);
            $this->assertFileExists($path);
            $before = Media::count();
            $response = $this->from(route('posts.create'))->post(route('posts.store'), [
                'body' => 'Pomiar HEIC 119', 'visibility' => 'private',
                'photos' => [new UploadedFile($path, $sample, mime_content_type($path), null, true)],
            ]);
            $response->assertSessionHasErrors('photos.0');
            $errors = $response->baseResponse->getSession()->get('errors')->get('photos.0');
            $page = $this->get(route('posts.create'));
            fwrite(STDOUT, json_encode([
                'sample' => $sample, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
                'mime' => mime_content_type($path), 'getimagesize' => @getimagesize($path),
                'reason' => RozpoznanieZdjecia::rozpoznaj($path)?->powod,
                'http' => $response->status(), 'errors' => $errors,
                'media_delta' => Media::count() - $before, 'posts' => Post::count(),
                'queued_media_jobs' => Queue::pushed(ProcessUploadedImage::class)->count(),
                'stored_files' => count(Storage::disk('public')->allFiles()),
                'body_retained_in_html' => str_contains($page->getContent(), 'Pomiar HEIC 119'),
                'private_retained_in_html' => (bool) preg_match('/value="private"\s+checked/', $page->getContent()),
                'signals' => ProductSignal::where('signal_name', 'photo_upload_failed')->pluck('properties')->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }
}
