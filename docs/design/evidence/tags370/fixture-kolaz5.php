<?php

declare(strict_types=1);

/**
 * Fixture #370 — tag z kolażem pięciu zdjęć od PIĘCIU różnych osób.
 * Wyłącznie lokalny runtime, baza kuking_pasek_370 na 127.0.0.1:55439.
 * Obrazy są rysowane na miejscu i JAWNIE oznaczone jako dane testowe —
 * to nie są zdjęcia żadnego użytkownika.
 */

use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tag = Tag::factory()->create([
    'name' => 'Odbior kolaz 5',
    'normalized_name' => Tag::znormalizujNazwe('Odbior kolaz 5'),
    'slug' => 'odbior-kolaz-5',
]);

$rysuj = function (string $sciezka, int $w, int $h, string $podpis): void {
    $im = imagecreatetruecolor($w, $h);
    $paleta = [[214, 90, 70], [90, 140, 96], [96, 110, 170], [190, 150, 60], [140, 96, 150]];
    $i = abs(crc32($podpis)) % 5;
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, ...$paleta[$i]));
    $bialy = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 12, 12, 'DANE TESTOWE', $bialy);
    imagestring($im, 5, 12, 40, $podpis, $bialy);
    imagestring($im, 3, 12, $h - 24, 'nie jest zdjeciem uzytkownika', $bialy);
    $tmp = tempnam(sys_get_temp_dir(), 'fx').'.webp';
    imagewebp($im, $tmp, 82);
    imagedestroy($im);
    Storage::disk('public')->put($sciezka, (string) file_get_contents($tmp));
    @unlink($tmp);
};

$imiona = ['Anna', 'Barbara', 'Celina', 'Damian', 'Ewelina'];

foreach ($imiona as $nr => $imie) {
    $autor = User::factory()->create();
    // UserFactory zakłada profil sama — nadajemy mu tylko czytelną nazwę.
    $autor->profile()->update([
        'username' => 'odbior'.mb_strtolower($imie),
        'display_name' => $imie.' Testowa',
    ]);
    $autor->refresh();
    $post = Post::factory()->for($autor, 'author')->create([
        'body' => 'Wpis testowy numer '.($nr + 1).' do odbioru kolazu #370.',
        'visibility' => Post::VISIBILITY_PUBLIC,
        'status' => Post::STATUS_PUBLISHED,
        'published_at' => now()->subMinutes(5 * (5 - $nr)),
    ]);

    $media = Media::factory()->create(['owner_id' => $autor->id]);
    foreach ($media->metadata['variants'] as $nazwa => $wariant) {
        $rysuj($wariant['key'], $wariant['width'], $wariant['height'], $imie);
    }
    $post->media()->attach($media->id, ['position' => 0]);
    $post->tags()->attach($tag->id, ['position' => 0, 'dodany_recznie' => true]);
}

echo "tag: /tag/{$tag->slug}\n";
echo 'autorow: '.count($imiona)."\n";
echo 'wpisow w tagu: '.$tag->posts()->count()."\n";
echo "FIXTURE-OK\n";
