<?php

declare(strict_types=1);

/*
 * Fixture do oglądu odnośnika `#tag` w treści wpisu (issue #737).
 *
 * Bierze najnowszy publiczny wpis ze zdjęciem z danych demonstracyjnych
 * i przepisuje jego treść tak, żeby tagi stały w DWÓCH SĄSIEDNICH WIERSZACH
 * — bo to ten układ sprawdza, czy powiększone pole dotknięcia nie kradnie
 * kliknięć wierszowi obok. Idzie przez `EditPost`, a nie przez `update()`,
 * żeby relacja tagów powstała TĄ SAMĄ DROGĄ co w produkcji.
 *
 * Wypisuje adres wpisu — podaj go skryptowi jako `SCIEZKA`.
 */

use App\Domain\Posts\Actions\EditPost;
use App\Models\Post;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Ten fixture ZMIENIA treść wpisu, więc wolno mu tylko na izolowanej,
// lokalnej bazie pomiarowej — nigdy na bazie, w której ktoś ma swoje dane.
$connection = DB::connection();
if (! $app->environment(['local', 'testing'])
    || $connection->getDriverName() !== 'pgsql'
    || ! str_ends_with($connection->getDatabaseName(), '_a11y')
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, 'Ogląd 737 wymaga izolowanej lokalnej bazy pomiarowej (nazwa kończąca się na _a11y).'.PHP_EOL);
    exit(1);
}

$post = Post::query()
    ->where('visibility', Post::VISIBILITY_PUBLIC)
    ->where('status', Post::STATUS_PUBLISHED)
    ->whereHas('media')
    ->orderByDesc('published_at')
    ->firstOrFail();

// Od #1361 akcja wymaga jawnego aktora; edytuje autor wpisu — jak w produkcji.
app(EditPost::class)->handle(
    $post->author,
    $post,
    "Rolada z kurczaka, tak jak robiła ją babcia. #rolada\n#ciasto #nasłodko na koniec.",
    'public',
    [],
);

echo parse_url($post->fresh()->url(), PHP_URL_PATH), PHP_EOL;
