<?php

declare(strict_types=1);

use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
$connection = DB::connection();
if (! $app->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql' || ! str_starts_with($connection->getDatabaseName(), 'kuking_port')) {
    throw new RuntimeException('Pomiar534 wymaga izolowanej bazy kuking_port*.');
}
$owner = Profile::where('username', 'ania')->firstOrFail()->user;
if (($argv[1] ?? '') === 'sprzataj') {
    $draft = Recipe::whereKey($argv[2])->where('author_id', $owner->id)->where('title', 'Pomiar kontrastu podpowiedzi534')->where('status', Recipe::STATUS_DRAFT)->firstOrFail();
    $draft->forceDelete();
    if (Recipe::withTrashed()->whereKey($argv[2])->exists()) {
        throw new RuntimeException('Nie usunięto szkicu pomiarowego534.');
    }
    exit;
}
$post = $owner->posts()->where('status', 'published')->where('visibility', 'public')->firstOrFail();
$draft = Recipe::factory()->draft()->create(['author_id' => $owner->id, 'title' => 'Pomiar kontrastu podpowiedzi534']);
echo json_encode(['wpis' => route('posts.show', $post, false), 'szkice' => route('add', [], false), 'draft' => $draft->id], JSON_THROW_ON_ERROR);
