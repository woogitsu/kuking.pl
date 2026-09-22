<?php

declare(strict_types=1);

// Dane wyłącznie do lokalnego pomiaru, nigdy do seedera ani produkcji.
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('local') || $db->getConfig('host') !== '127.0.0.1'
    || (int) $db->getConfig('port') !== 55439
    || $db->getDatabaseName() !== 'kuking_flota_gpt_tagi_miejsce_a11y') {
    throw new RuntimeException('Użyj własnej bazy pomiarowej na porcie 55439.');
}
if (User::exists() || Tag::exists()) {
    throw new RuntimeException('Fixture wymaga pustej bazy; nie nadpisuje danych.');
}
$image = imagecreatetruecolor(640, 480);
imagefill($image, 0, 0, imagecolorallocate($image, 210, 185, 140));
ob_start();
imagewebp($image);
$bytes = ob_get_clean();
$key = 'pomiar-tagi-647.webp';
Storage::disk('public')->put($key, $bytes);
$variant = ['key' => $key, 'width' => 640, 'height' => 480, 'bytes' => strlen($bytes)];
$owner = User::factory()->create(['email' => 'tagi647@example.test', 'wants_weekly_digest' => false]);
$owner->profile()->update(['username' => 'pomiar647', 'display_name' => 'Konto pomiarowe']);
$manual = Tag::factory()->create(['name' => 'zupa pomidorowa', 'slug' => 'zupa-pomidorowa']);
$post = Post::factory()->create(['author_id' => $owner->id, 'body' => 'Opis do edycji.']);
$post->tags()->attach($manual, ['dodany_recznie' => true]);
$question = Post::factory()->question()->create(['author_id' => $owner->id]);
$question->tags()->attach($manual, ['dodany_recznie' => true]);
foreach ([2, 3, 5, 10, 42] as $count) {
    $tag = Tag::factory()->create(['name' => 'pomiar '.$count, 'slug' => 'pomiar-'.$count]);
    for ($i = 1; $i <= $count; $i++) {
        $author = User::factory()->create(['wants_weekly_digest' => false]);
        $author->profile()->update(['username' => 'pomiar'.$count.'osoba'.$i, 'display_name' => 'Osoba pomiarowa '.$i]);
        $item = Post::factory()->create(['author_id' => $author->id, 'body' => 'Publiczne dane pomiarowe.']);
        $item->tags()->attach($tag);
        for ($photo = 0; $photo < ($i === 1 ? max(1, 6 - $count) : 1); $photo++) {
            $media = Media::factory()->create(['owner_id' => $author->id, 'disk' => 'public', 'variants_disk' => 'public',
                'width' => 640, 'height' => 480, 'bytes' => strlen($bytes),
                'metadata' => ['variants' => array_fill_keys(['thumb', 'feed', 'large'], $variant)]]);
            $item->media()->attach($media, ['position' => $photo]);
        }
    }
}
echo json_encode(['edit' => route('posts.edit', $post, false), 'questionEdit' => route('posts.edit', $question, false)], JSON_THROW_ON_ERROR).PHP_EOL;
