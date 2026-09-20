<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage());
    exit(1);
});
$db = DB::connection();
if ($db->getDriverName() !== 'pgsql' || $app->environment('production') || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55439' || $db->getDatabaseName() !== 'kuking_585_benchmark') {
    throw new RuntimeException('Wymagana lokalna baza kuking_585_benchmark na 127.0.0.1:55439.');
}
$counts = [
    'media' => DB::table('media')->where('alt_text', 'Pomiar585 media syntetyczne')->count(),
    'recipe_heroes' => DB::table('recipes')->join('media', 'media.id', '=', 'recipes.hero_media_id')->where('media.alt_text', 'Pomiar585 media syntetyczne')->count(),
    'authors' => DB::table('users')->where('email', 'like', 'scale585-author-%')->count(),
    'posts' => DB::table('posts')->join('users', 'users.id', '=', 'posts.author_id')->where('users.email', 'like', 'scale585-author-%')->count(),
    'comments' => DB::table('comments')->where('body', 'like', 'Wzbogacenie585%')->count(),
    'saves' => DB::table('collection_items')->join('collections', 'collections.id', '=', 'collection_items.collection_id')->join('users', 'users.id', '=', 'collections.owner_id')->where('users.email', 'like', 'scale585-viewer-%')->count(),
    'linked_recipes' => DB::table('posts')->join('users', 'users.id', '=', 'posts.author_id')->where('users.email', 'like', 'scale585-author-%')->whereNotNull('posts.recipe_id')->count(),
];
$expected = ['media' => 399, 'recipe_heroes' => 100, 'authors' => 2100, 'posts' => 50000, 'comments' => 800, 'saves' => 800, 'linked_recipes' => 100];
if ($counts !== $expected) {
    throw new RuntimeException('Niekompletny zbiór pomiarowy: '.json_encode($counts, JSON_THROW_ON_ERROR));
}
echo json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL;
