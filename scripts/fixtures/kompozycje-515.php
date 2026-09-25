<?php

declare(strict_types=1);

use App\Domain\Tags\LiczbyTagowWCache;
use App\Domain\Tags\TagCollage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
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
if ($app->environment('production') || ! str_starts_with((string) config('database.connections.pgsql.database'), 'kuking_port')) {
    throw new RuntimeException('Pomiar515 wymaga izolowanej bazy kuking_port*.');
}
$mode = $argv[1] ?? '';
if (! in_array($mode, ['zapisz', 'przywroc', 'puste', 'pelne', 'fotografia'], true) || empty($argv[2])) {
    throw new InvalidArgumentException('Podaj tryb pomiaru515 i plik kopii danych.');
}
if ($mode === 'zapisz') {
    file_put_contents($argv[2], json_encode(['promotions' => TagPromotion::all()->map(fn ($p) => $p->getAttributes()), 'created_tags' => [], 'tag_ids' => Tag::orderBy('id')->pluck('id')], JSON_THROW_ON_ERROR));
    exit;
}
if ($mode === 'przywroc') {
    $saved = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($saved) {
        if (isset($saved['photo681'])) {
            Post::withTrashed()->whereKey($saved['photo681']['post'])->forceDelete();
            Media::whereKey($saved['photo681']['media'])->delete();
            User::whereKey($saved['photo681']['user'])->delete();
        }
        TagPromotion::query()->delete();
        if ($saved['promotions']) {
            DB::table('tag_promotions')->insert($saved['promotions']);
        }
        Tag::whereKey($saved['created_tags'])->delete();
    });
    if (isset($saved['photo681'])) {
        Storage::disk('public')->delete($saved['photo681']['key']);
    }
    $wszystkie = Tag::pluck('id')->map(fn ($id): string => (string) $id)->all();
    TagCollage::zapomnijGoscia($wszystkie);
    LiczbyTagowWCache::zapomnij($wszystkie);
    if (TagPromotion::all()->keyBy('tag_id')->map(fn ($p) => $p->getAttributes())->all() != collect($saved['promotions'])->keyBy('tag_id')->all() || Tag::orderBy('id')->pluck('id')->all() !== $saved['tag_ids']) {
        throw new RuntimeException('Nie odtworzono promocji515.');
    }
    exit;
}
$backup = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'fotografia') {
    $ids = array_combine(['post', 'media', 'user'], array_map(fn () => (string) Str::uuid(), range(1, 3)));
    $ids['key'] = 'pomiar681/'.$ids['media'].'.webp';
    $backup['photo681'] = $ids;
    file_put_contents($argv[2], json_encode($backup, JSON_THROW_ON_ERROR));
    $image = imagecreatetruecolor(960, 720);
    imagefill($image, 0, 0, imagecolorallocate($image, 245, 230, 190));
    ob_start();
    imagewebp($image);
    $bytes = ob_get_clean();
    Storage::disk('public')->put($ids['key'], $bytes);
    DB::transaction(function () use ($ids, $bytes) {
        $user = User::factory()->create(['id' => $ids['user']]);
        $user->profile()->update(['display_name' => 'Aleksandra Katarzyna z kuchni']);
        $post = Post::factory()->create(['id' => $ids['post'], 'author_id' => $user->id]);
        $post->tags()->attach(Tag::where('slug', 'pomiar515-1')->firstOrFail());
        $variant = ['key' => $ids['key'], 'width' => 960, 'height' => 720, 'bytes' => strlen($bytes)];
        $media = Media::factory()->create(['id' => $ids['media'], 'owner_id' => $user->id,
            'disk' => 'public', 'variants_disk' => 'public', 'object_key' => $ids['key'],
            'width' => 960, 'height' => 720, 'bytes' => strlen($bytes),
            'metadata' => ['variants' => array_fill_keys(['thumb', 'feed', 'large'], $variant)]]);
        $post->media()->attach($media);
    });
    // Strony tagów trzymają dobór kolażu i liczby gościa w cache
    // (audyt B4 W2). Wcześniejsze kroki pomiaru odwiedzają `/tagi` jako
    // gość w stanie bez tego zdjęcia, więc bez unieważnienia katalog przez
    // `CACHE_SEKUND` pokazywałby stary, pusty kolaż (K681_BRAK_FOTOGRAFII).
    $tagId = (string) Tag::where('slug', 'pomiar515-1')->value('id');
    TagCollage::zapomnijGoscia([$tagId]);
    LiczbyTagowWCache::zapomnij([$tagId]);
    exit;
}
TagPromotion::query()->delete();
if ($mode === 'puste') {
    exit;
}
foreach ([1, 2, 3] as $i) {
    $tag = Tag::firstOrCreate(['slug' => 'pomiar515-'.$i], ['name' => $i === 2 ? str_repeat('D', 30) : 'Pomiar515 '.$i, 'normalized_name' => 'pomiar515 '.$i]);
    if ($tag->wasRecentlyCreated) {
        $backup['created_tags'][] = $tag->id;
        file_put_contents($argv[2], json_encode($backup, JSON_THROW_ON_ERROR));
    }
    TagPromotion::create(['tag_id' => $tag->id, 'position' => $i, 'note' => $i === 3 ? null : str_repeat('Prawdziwy opis pomiarowy gospodarza. ', 5).'KONIEC515']);
}
echo json_encode(['tagi' => Tag::promowane()->get()->map(fn ($t) => ['name' => $t->name, 'note' => $t->promotion->note, 'url' => route('tags.show', $t, false)])], JSON_THROW_ON_ERROR);
