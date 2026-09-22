<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Post;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Niezapisane modele: render prawdziwych komponentów bez rekordów w bazie.
$result = [];
foreach (['poziome' => [1600, 1200], 'pionowe' => [900, 1600]] as $orientation => [$width, $height]) {
    $photos = collect();
    foreach (range(1, 3) as $number) {
        $media = new Media;
        $media->forceFill([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $number),
            'disk' => 'public',
            'status' => Media::STATUS_READY,
            'alt_text' => 'Zdjęcie demonstracyjne '.$number,
            'metadata' => ['variants' => array_fill_keys(['thumb', 'feed', 'large'], [
                'key' => 'fixture.webp', 'width' => $width, 'height' => $height,
            ])],
        ]);
        $photos->push($media);
    }
    $post = new Post;
    $post->forceFill(['id' => '00000000-0000-4000-8000-000000000561']);
    $post->setRelation('media', $photos);
    foreach ([
        'pojedyncze' => '<div class="photo-grid"><x-photo :media="$media" /></div>',
        'karuzela' => '<x-karuzela-zdjec :post="$post" />',
        'kolaz' => '<x-kolaz-zdjec :post="$post" />',
    ] as $layout => $template) {
        $result[$orientation.'/'.$layout] = Blade::render($template, ['media' => $photos->first(), 'post' => $post]);
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
