<?php

declare(strict_types=1);
use App\Models\Media;
use App\Models\Post;
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
// Metadane do pomiaru SQL/Eloquent, bez udawania pomiaru pobierania plików.
if (DB::table('media')->where('alt_text', 'Pomiar585 media syntetyczne')->exists()) {
    throw new RuntimeException('Media pomiarowe już istnieją.');
}
$result = $db->transaction(function () {
    $posts = Post::whereHas('author', fn ($q) => $q->where('email', 'like', 'scale585-author-%'))
        ->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get();
    if ($posts->count() !== 200) {
        throw new RuntimeException('Brak wpisów pomiarowych.');
    }
    $count = 0;
    foreach ($posts as $i => $post) {
        for ($j = 0; $j <= $i % 3; $j++) {
            $media = Media::factory()->create(['owner_id' => $post->author_id, 'alt_text' => 'Pomiar585 media syntetyczne']);
            $post->media()->attach($media->id, ['position' => $j]);
            if ($j === 0 && $post->recipe_id !== null) {
                $post->recipe->update(['hero_media_id' => $media->id]);
            }
            $count++;
        }
    }

    return ['media_records' => $count, 'scope' => 'Metadane relacji; bez plików i transferu zdjęć.'];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
