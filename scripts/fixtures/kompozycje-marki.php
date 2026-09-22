<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Wyłącznie osobna baza pomiaru portu. Nigdy produkcja ani zwykła baza testów.
if ($app->environment('production') || ! str_starts_with((string) config('database.connections.pgsql.database'), 'kuking_port')) {
    throw new RuntimeException('Fixture wymaga osobnej bazy kuking_port*.');
}

$slug = 'pomiar-kompozycji-509';
if (! Recipe::where('slug', $slug)->exists()) {
    $zip = new ZipArchive;
    if ($zip->open(base_path('docs/design/references/KuKing-styl-wizualizacja-konstytucja.zip')) !== true) {
        throw new RuntimeException('Nie można odczytać fotografii poglądowej z oryginału.');
    }
    $bytes = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'placki-ziemniaczane.webp')) {
            $bytes = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    if ($bytes === false || ($image = imagecreatefromstring($bytes)) === false) {
        throw new RuntimeException('Brak poprawnego obrazu do pomiaru.');
    }
    $author = Profile::where('username', 'zofia_z_bieszczad')->firstOrFail()->user;
    $key = 'media/pomiar-509.webp';
    $variants = [];
    foreach (['thumb' => 320, 'feed' => 960, 'large' => 1536] as $name => $width) {
        $scaled = imagescale($image, $width);
        ob_start();
        imagewebp($scaled, null, 85);
        $content = ob_get_clean();
        $variantKey = Media::kluczPublicznegoWariantu($key, $name);
        Storage::disk('public')->put($variantKey, $content);
        $variants[$name] = ['key' => $variantKey, 'width' => imagesx($scaled), 'height' => imagesy($scaled)];
    }
    $media = Media::create([
        'owner_id' => $author->id, 'disk' => 'public', 'object_key' => $key,
        'mime_type' => 'image/webp', 'bytes' => strlen($bytes), 'width' => imagesx($image), 'height' => imagesy($image),
        'status' => Media::STATUS_READY, 'alt_text' => 'Fotografia poglądowa z wizualizacji — dane pomiarowe',
        'metadata' => ['variants' => $variants],
    ]);
    Recipe::factory()->create([
        'author_id' => $author->id, 'slug' => $slug, 'hero_media_id' => $media->id,
        'title' => 'Placki ziemniaczane z sosem grzybowym — pomiar kompozycji',
        'summary' => 'Dane demonstracyjne. Fotografia z oryginalnej wizualizacji służy wyłącznie do sprawdzenia układu i powiększania zdjęcia.',
    ]);
    Recipe::factory()->create([
        'author_id' => $author->id, 'slug' => $slug.'-bez-zdjecia', 'hero_media_id' => null,
        'title' => str_repeat('Długi tytuł rodzinnego przepisu z wieloma składnikami ', 3),
        'summary' => str_repeat('Długi opis pomiarowy bez zdjęcia, który musi pozostać czytelny w całości. ', 12),
    ]);
}
echo json_encode(['przepis' => '/przepisy/'.$slug, 'bezZdjecia' => '/przepisy/'.$slug.'-bez-zdjecia'], JSON_THROW_ON_ERROR);
