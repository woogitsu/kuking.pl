<?php

declare(strict_types=1);
use App\Domain\Collections\Actions\SavePostToCollection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
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
if (Comment::where('body', 'like', 'Wzbogacenie585%')->exists()) {
    throw new RuntimeException('Dane zostały już wzbogacone. Nie tworzymy duplikatów.');
}
$result = $db->transaction(function () {
    $viewers = User::where('email', 'like', 'scale585-viewer-%')->get();
    $posts = Post::whereHas('author', fn ($q) => $q->where('email', 'like', 'scale585-author-%'))->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get();
    if ($viewers->count() !== 4 || $posts->count() !== 200) {
        throw new RuntimeException('Brak pełnego bazowego zbioru pomiarowego.');
    }
    $comments = 0;
    $recipes = 0;
    $saves = 0;
    foreach ($posts as $i => $post) {
        foreach ($viewers as $viewer) {
            Comment::create(['author_id' => $viewer->id, 'post_id' => $post->id, 'body' => 'Wzbogacenie585 komentarz syntetyczny', 'status' => 'published']);
            $comments++;
            app(SavePostToCollection::class)->handle($viewer, $post);
            $saves++;
        }
        if ($i % 2 === 0) {
            $recipe = Recipe::factory()->create(['author_id' => $post->author_id]);
            $post->update(['recipe_id' => $recipe->id]);
            $recipes++;
        }
    }

    return compact('comments', 'recipes', 'saves');
});
echo json_encode($result, JSON_THROW_ON_ERROR);
