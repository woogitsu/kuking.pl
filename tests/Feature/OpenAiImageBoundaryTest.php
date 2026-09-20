<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujAwatar;
use App\Models\Media;
use App\Models\Post;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiImageBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['results' => [['category_scores' => ['hate' => 0.01]]]])]);
        Notification::fake();
        Storage::fake('local');
        config(['kuking.moderation.model.klucz' => 'atrapa', 'kuking.moderation.model.ocenia_zdjecia' => true,
            'kuking.moderation.sygnaly.wlaczone' => true]);
    }

    public static function invalidImages(): array
    {
        $cases = [
            'uszkodzony-typ-thumb' => [['thumb' => 'broken', 'large' => ['key' => 'large.webp']], 2048, 1536],
            'maly-zamiennik' => [['large' => ['key' => 'large.webp']], 320, 240],
            'historyczne-large' => [['large' => ['key' => 'large.webp']], 2048, 1536],
            'brak-klucza-thumb' => [['thumb' => ['width' => 320], 'large' => ['key' => 'large.webp']], 2048, 1536],
            'thumb-klamie-o-wymiarach' => [['thumb' => ['key' => 'large.webp', 'width' => 320, 'height' => 240]], 2048, 1536],
            'za-wysoki' => [['thumb' => ['key' => 'large.webp']], 240, 321],
            'za-szeroki' => [['thumb' => ['key' => 'large.webp']], 321, 240],
            'brak-pliku-thumb' => [['thumb' => ['key' => 'missing.webp'], 'large' => ['key' => 'large.webp']], 2048, 1536],
            'uszkodzone-bajty' => [['thumb' => ['key' => 'broken.webp']], 2048, 1536],
        ];
        $result = [];
        foreach (['wpis', 'awatar'] as $path) {
            foreach ($cases as $name => $args) {
                $result[$path.'-'.$name] = [$path, ...$args];
            }
        }

        return $result;
    }

    #[DataProvider('invalidImages')]
    public function test_nieprawidlowa_miniatura_nie_wychodzi(string $path, array $variants, int $width, int $height): void
    {
        $logger = Log::spy();
        Storage::disk('local')->put('large.webp', (string) ImageManager::gd()->create($width, $height)->toWebp());
        Storage::disk('local')->put('broken.webp', 'To nie jest obraz.');
        $this->analyse($path, $variants);
        $sizes = [];
        foreach (Http::recorded() as [$request]) {
            $uri = $request['input'][0]['image_url']['url'];
            $size = getimagesizefromstring(base64_decode(explode(',', $uri, 2)[1]));
            $sizes[] = $size[0].'x'.$size[1];
        }
        $this->assertSame([], $sizes, 'Do atrapy wyslano obraz: '.implode(', ', $sizes));
        $logger->shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_prawdziwe_bajty_miniatury_przechodza_na_obu_drogach(): void
    {
        Storage::disk('local')->put('thumb.webp', (string) ImageManager::gd()->create(320, 240)->toWebp());
        foreach (['wpis', 'awatar'] as $path) {
            $this->analyse($path, ['thumb' => ['key' => 'thumb.webp']]);
        }
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $uri = $request['input'][0]['image_url']['url'];
            $size = getimagesizefromstring(base64_decode(explode(',', $uri, 2)[1]));
            $this->assertSame([320, 240, IMAGETYPE_JPEG], array_slice($size, 0, 3));
        }
    }

    private function analyse(string $path, array $variants): void
    {
        $owner = $this->user('osoba'.Media::query()->count());
        $media = Media::factory()->create(['owner_id' => $owner->id, 'status' => Media::STATUS_READY,
            'disk' => 'local', 'variants_disk' => null, 'metadata' => ['variants' => $variants]]);
        if ($path === 'awatar') {
            $owner->profile->update(['avatar_media_id' => $media->id]);
            dispatch_sync(new PrzeanalizujAwatar($media->id));
        } else {
            $post = Post::factory()->create(['author_id' => $owner->id, 'body' => '']);
            $post->media()->attach($media);
            app(OcenaModelem::class)->dla($post);
        }
    }
}
