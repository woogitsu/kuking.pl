<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
// Sprawdzamy rozwiązaną konfigurację połączenia, także po DB_URL/config:cache.
$connection = DB::connection();
$database = $connection->getDatabaseName();
$allowed = in_array($database, ['kuking_a11y', 'kuking_test_a11y', 'kuking_proof431'], true)
    || ($database === 'kuking_test' && getenv('GITHUB_ACTIONS') === 'true');
if (! $app->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql' || ! $allowed
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
    || config('filesystems.disks.public.driver') !== 'local') {
    throw new RuntimeException('Karuzela431 wymaga izolowanej lokalnej bazy pomiarowej i lokalnego dysku public.');
}
$marker = 'Pomiar431: pionowe i poziome zdjęcie';
if (($argv[1] ?? '') === 'sprzataj') {
    $id = $argv[2] ?? '';
    if (! Str::isUuid($id)) {
        throw new RuntimeException('Nieprawidłowy identyfikator próbki431.');
    }
    $post = Post::whereKey($id)->where('body', $marker)->firstOrFail();
    $prefix = 'media/pomiar431/'.$id.'/';
    $media = $post->media;
    foreach ($media as $photo) {
        if (! str_starts_with($photo->object_key, $prefix) || $photo->disk !== 'public') {
            throw new RuntimeException('Próbka431 zawiera obce medium — odmowa sprzątania.');
        }
    }
    // Przy odmowie usunięcia plików zachowujemy wpis do bezpiecznego ponowienia.
    $disk = Storage::disk('public');
    if ($disk->directoryExists($prefix) && ! $disk->deleteDirectory($prefix)) {
        throw new RuntimeException('Nie usunięto katalogu próbki431.');
    }
    if ($disk->directoryExists($prefix)) {
        throw new RuntimeException('Katalog próbki431 pozostał po sprzątaniu.');
    }
    DB::transaction(function () use ($post, $media): void {
        $post->media()->detach();
        foreach ($media as $photo) {
            $photo->delete();
        }
        $post->forceDelete();
    });
    echo "posprzatano\n";
    exit;
}

$owner = Profile::where('username', 'ania')->firstOrFail()->user_id;
$id = (string) Str::uuid();
$prefix = 'media/pomiar431/'.$id.'/';
try {
    $post = DB::transaction(function () use ($owner, $id, $prefix, $marker): Post {
        $post = new Post([
            'author_id' => $owner, 'body' => $marker, 'visibility' => 'public',
            'status' => 'published', 'display_mode' => 'carousel', 'published_at' => now(),
        ]);
        $post->id = $id;
        $post->save();
        foreach ([[600, 800], [800, 450]] as $index => [$width, $height]) {
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, 80 + $index * 90, 110, 150));
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            $key = $prefix.$index.'.png';
            if (! Storage::disk('public')->put($key, $bytes)) {
                throw new RuntimeException('Nie zapisano zdjęcia próbki431.');
            }
            $variant = ['key' => $key, 'width' => $width, 'height' => $height, 'bytes' => strlen($bytes)];
            $photo = Media::create([
                'owner_id' => $owner, 'disk' => 'public', 'object_key' => $prefix.'original-'.$index.'.png',
                'mime_type' => 'image/png', 'bytes' => strlen($bytes), 'width' => $width, 'height' => $height,
                'status' => Media::STATUS_READY, 'alt_text' => $index === 0 ? 'Pionowe zdjęcie pomiarowe' : 'Poziome zdjęcie pomiarowe',
                'metadata' => ['variants' => ['thumb' => $variant, 'feed' => $variant, 'large' => $variant]],
            ]);
            $post->media()->attach($photo->id, ['position' => $index]);
        }

        return $post;
    });
} catch (Throwable $error) {
    Storage::disk('public')->deleteDirectory($prefix);
    throw $error;
}
echo json_encode(['id' => $post->id, 'path' => route('posts.show', $post, false)], JSON_THROW_ON_ERROR);
