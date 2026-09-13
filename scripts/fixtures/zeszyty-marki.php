<?php

declare(strict_types=1);

use App\Models\Collection;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_starts_with((string) config('database.connections.pgsql.database'), 'kuking_port')) {
    throw new RuntimeException('Fixture wymaga osobnej bazy kuking_port*.');
}

$owner = Profile::where('username', 'ania')->firstOrFail()->user;
$reference = Recipe::where('slug', 'pomiar-kompozycji-509')->firstOrFail();
if ($reference->hero_media_id === null) {
    throw new RuntimeException('Najpierw przygotuj fotografię przez fixture kompozycje-marki.php.');
}
$collection = Collection::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Pomiar 511 — rodzinne przepisy'], [
    'description' => 'Zeszyt danych pomiarowych. Pełne nazwy i rzeczywiste odnośniki.',
    'visibility' => 'private', 'is_default' => false,
]);
foreach (['Pomiar 511 — niedzielne obiady i długie nazwy zeszytów', 'Pomiar 511 — pusty zeszyt'] as $name) {
    Collection::firstOrCreate(['owner_id' => $owner->id, 'name' => $name], [
        'visibility' => 'private', 'is_default' => false,
    ]);
}
$titles = [
    'Placki ziemniaczane z fotografią — pomiar 511',
    'Przepis bez zdjęcia — pomiar 511',
    'Rodzinny przepis na pieczone warzywa z aromatycznym sosem, kaszą i chrupiącymi pestkami na wspólny niedzielny obiad — KONIEC511',
];
foreach ($titles as $index => $title) {
    $recipe = Recipe::where('slug', 'pomiar-zeszytu-511-'.$index)->first() ?? Recipe::factory()->create([
        'slug' => 'pomiar-zeszytu-511-'.$index,
        'author_id' => $reference->author_id, 'title' => $title,
        'summary' => 'Dane pomiarowe kompozycji zeszytu.',
        'hero_media_id' => $index === 1 ? null : $reference->hero_media_id,
        'visibility' => 'public', 'status' => 'published', 'published_at' => now()->subYear(),
    ]);
    $collection->recipes()->syncWithoutDetaching([$recipe->id => ['created_at' => now()->addMinutes($index + 1)]]);
}
echo json_encode(['zeszyt' => '/zeszyt/'.$collection->id], JSON_THROW_ON_ERROR);
