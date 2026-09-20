<?php

declare(strict_types=1);

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

// Wyłącznie izolowany zbiór demonstracyjny. Bez danych i połączeń produkcyjnych.
require __DIR__.'/../tests/bootstrap.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Samodzielny skrypt musi zgłaszać błąd także kodem procesu, nie tylko tekstem.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Pomiar przerwany: '.$error->getMessage().PHP_EOL);
    exit(1);
});
$connection = DB::connection();
if ($app->environment('production')
    || $connection->getDriverName() !== 'pgsql'
    || $connection->getConfig('host') !== '127.0.0.1'
    || (string) $connection->getConfig('port') !== '55439'
    || $connection->getDatabaseName() !== 'kuking_585_benchmark') {
    throw new RuntimeException('Pomiar wymaga lokalnej bazy kuking_585_benchmark na 127.0.0.1:55439.');
}

$viewer = User::query()->findOrFail($argv[1] ?? '');
$mode = $argv[2] ?? 'following';
if (! in_array($mode, ['following', 'discover'], true)) {
    throw new InvalidArgumentException('Wybierz following albo discover.');
}
$encodedCursor = $argv[3] ?? '';
$cursor = $encodedCursor !== '' ? Cursor::fromEncoded($encodedCursor) : null;
if ($encodedCursor !== '' && $cursor === null) {
    throw new InvalidArgumentException('Nieprawidłowy kursor feedu.');
}
CursorPaginator::currentCursorResolver(static fn () => $cursor);
$databaseSettings = $connection->selectOne("SELECT current_setting('server_version') AS version, current_setting('jit') AS jit, current_setting('jit_above_cost') AS jit_above_cost, current_setting('jit_inline_above_cost') AS jit_inline_above_cost, current_setting('jit_optimize_above_cost') AS jit_optimize_above_cost");
$perPage = isset($argv[4]) ? filter_var($argv[4], FILTER_VALIDATE_INT) : null;
if ($perPage !== null && ($perPage === false || $perPage < 1 || $perPage > 100)) {
    throw new InvalidArgumentException('Rozmiar strony musi wynosić od 1 do 100.');
}

$connection->flushQueryLog();
$connection->enableQueryLog();
$start = hrtime(true);
$page = $mode === 'following'
    ? app(FollowingFeed::class)->paginate($viewer, $perPage)
    : app(DiscoverFeed::class)->paginate($viewer, $perPage);
$elapsed = (hrtime(true) - $start) / 1e6;
$queries = $connection->getQueryLog();
$connection->disableQueryLog();

$plans = [];
$hydrationQueries = 0;
foreach ($queries as $query) {
    if (! preg_match('/^select\b/i', ltrim($query['query']))) {
        throw new RuntimeException('Pomiar feedu wykonał zapytanie inne niż SELECT.');
    }
    // Powtórne wykonanie po paginate(): osobna próbka z rozgrzanym cache,
    // nie składniki wcześniej zmierzonego czasu requestu ani hydratacji.
    $startPrepare = hrtime(true);
    $statement = $connection->getPdo()->prepare($query['query']);
    $prepared = hrtime(true);
    $statement->execute($connection->prepareBindings($query['bindings']));
    $executed = hrtime(true);
    $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $fetched = hrtime(true);
    $statement->closeCursor();
    $hydration = null;
    if (str_starts_with($query['query'], 'select "posts".*')) {
        $hydrationQueries++;
        // Osobna hydratacja wierszy PDO, bez relacji i renderowania Blade.
        $mappingStart = hrtime(true);
        $mapped = Post::hydrate($rawRows);
        $hydration = ['ms' => (hrtime(true) - $mappingStart) / 1e6, 'rows' => $mapped->count()];
        if ($mapped->modelKeys() !== array_column($rawRows, 'id')) {
            throw new RuntimeException('Hydratacja zmieniła kolejność lub identyfikatory wpisów.');
        }
        $pageIds = array_map(static fn ($post) => $post->getKey(), $page->items());
        $rawIds = $mapped->modelKeys();
        if ($cursor?->pointsToPreviousItems()) {
            $rawIds = array_reverse(array_slice($rawIds, 0, count($pageIds)));
        } else {
            $rawIds = array_slice($rawIds, 0, count($pageIds));
        }
        if ($rawIds !== $pageIds || $mapped->count() > count($pageIds) + 1) {
            throw new RuntimeException('Wiersze pomiaru hydratacji nie odpowiadają stronie kursora.');
        }
    }
    $plan = $connection->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], $query['bindings']);
    $plans[] = [
        'sql' => $query['query'],
        'bindings' => $connection->prepareBindings($query['bindings']),
        'laravel_ms' => $query['time'],
        'post_hydration_replay' => $hydration,
        'pdo_replay' => [
            'prepare_ms' => ($prepared - $startPrepare) / 1e6,
            'execute_ms' => ($executed - $prepared) / 1e6,
            'fetch_ms' => ($fetched - $executed) / 1e6,
            'rows' => count($rawRows),
        ],
        'plan' => json_decode($plan[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR),
    ];
}
if ($hydrationQueries !== 1) {
    throw new RuntimeException('Pomiar wymaga dokładnie jednego zapytania hydratacji wpisów.');
}

echo json_encode([
    'mode' => $mode,
    'viewer_id' => $viewer->getKey(),
    'following_count' => $viewer->following()->count(),
    'input_cursor' => $encodedCursor,
    'per_page' => $page->perPage(),
    'database_settings' => $databaseSettings,
    'source_sha256' => hash_file('sha256', (new ReflectionClass(
        $mode === 'following' ? FollowingFeed::class : DiscoverFeed::class,
    ))->getFileName()),
    // Obejmuje hydratację i eager loading; nie jest czasem pełnego HTTP requestu.
    'paginate_ms' => $elapsed,
    'queries' => $plans,
    'rows' => array_map(static fn ($post) => [
        'id' => $post->getKey(),
        'comments_count' => $post->comments_count,
        'zapisow_count' => $post->zapisow_count,
        'czy_zapisany' => $post->czy_zapisany,
        'relations' => array_keys($post->getRelations()),
        'media_ids' => $post->media->modelKeys(),
        'recipe_id' => $post->recipe?->getKey(),
        'recipe_hero_id' => $post->recipe?->heroMedia?->getKey(),
    ], $page->items()),
    'next_cursor' => $page->nextCursor()?->encode(),
    'previous_cursor' => $page->previousCursor()?->encode(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
