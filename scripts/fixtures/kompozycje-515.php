<?php

declare(strict_types=1);

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
if (! in_array($mode, ['zapisz', 'przywroc', 'puste', 'pelne'], true) || empty($argv[2])) {
    throw new InvalidArgumentException('Podaj tryb pomiaru515 i plik kopii danych.');
}
if ($mode === 'zapisz') {
    file_put_contents($argv[2], json_encode(['promotions' => TagPromotion::all()->map(fn ($p) => $p->getAttributes()), 'created_tags' => [], 'tag_ids' => Tag::orderBy('id')->pluck('id')], JSON_THROW_ON_ERROR));
    exit;
}
if ($mode === 'przywroc') {
    $saved = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($saved) {
        TagPromotion::query()->delete();
        if ($saved['promotions']) {
            DB::table('tag_promotions')->insert($saved['promotions']);
        }
        Tag::whereKey($saved['created_tags'])->delete();
    });
    if (TagPromotion::all()->keyBy('tag_id')->map(fn ($p) => $p->getAttributes())->all() != collect($saved['promotions'])->keyBy('tag_id')->all() || Tag::orderBy('id')->pluck('id')->all() !== $saved['tag_ids']) {
        throw new RuntimeException('Nie odtworzono promocji515.');
    }
    exit;
}
$backup = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
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
