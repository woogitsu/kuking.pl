<?php

declare(strict_types=1);

/**
 * A7-3 — pomiar na syntetycznej, reprezentatywnej skali.
 *
 * Uruchomienie WYŁĄCZNIE na osobnej bazie:
 *   DB_DATABASE=kuking_test_a7_perf php docs/zlecenia/dowody-a7/pomiar-wydajnosci.php
 *
 * Skrypt ma twardą bramkę nazwy bazy i nie zawiera żadnych danych produkcyjnych.
 */

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$baza = (string) DB::connection()->getDatabaseName();
if ($baza !== 'kuking_test_a7_perf') {
    fwrite(STDERR, "ODMOWA: oczekiwano DB_DATABASE=kuking_test_a7_perf, jest {$baza}.\n");
    exit(2);
}

function uuidA7(int $typ, int $n): string
{
    return sprintf('%08d-0000-0000-0000-%012d', $typ, $n);
}

function mediana(array $liczby): float
{
    sort($liczby, SORT_NUMERIC);
    $ile = count($liczby);
    if ($ile === 0) {
        return 0.0;
    }
    $srodek = intdiv($ile, 2);

    return $ile % 2 === 1
        ? (float) $liczby[$srodek]
        : ((float) $liczby[$srodek - 1] + (float) $liczby[$srodek]) / 2;
}

function planDla(array $zapytania): ?array
{
    foreach ($zapytania as $zapytanie) {
        if (! str_contains($zapytanie['sql'], 'from "posts"')) {
            continue;
        }
        if (! str_contains($zapytanie['sql'], 'order by')) {
            continue;
        }

        $wiersze = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$zapytanie['sql'], $zapytanie['bindings']);
        if ($wiersze === []) {
            return null;
        }

        $wartosc = array_values((array) $wiersze[0])[0] ?? null;
        if (! is_string($wartosc)) {
            return null;
        }

        return json_decode($wartosc, true, 512, JSON_THROW_ON_ERROR);
    }

    return null;
}

// -------------------------------------------------------------------------
// Dane syntetyczne. UUID-y są deterministyczne, żeby dowód był powtarzalny.
// -------------------------------------------------------------------------
DB::statement("SET statement_timeout = '120s'");

DB::unprepared(<<<'SQL'
INSERT INTO users (id, email, password, status, role, locale, text_scale, wants_weekly_digest, created_at, updated_at)
SELECT
    ('00000001-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    'a7+' || g || '@example.invalid',
    'nieuzywane-w-pomiarze',
    'active', 'user', 'pl', 100, false, now(), now()
FROM generate_series(1, 10000) AS g;

INSERT INTO profiles (user_id, username, display_name, created_at, updated_at)
SELECT
    ('00000001-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    'a7_user_' || lpad(g::text, 5, '0'),
    'Użytkownik A7 ' || g,
    now(), now()
FROM generate_series(1, 10000) AS g;

INSERT INTO follows (follower_id, followed_id, created_at)
SELECT
    '00000001-0000-0000-0000-000000000001'::uuid,
    ('00000001-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    now()
FROM generate_series(2, 501) AS g;

INSERT INTO recipes (id, author_id, title, slug, visibility, status, source_type, published_at, created_at, updated_at)
SELECT
    ('00000002-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    ('00000001-0000-0000-0000-' || lpad((((g - 1) % 10000) + 1)::text, 12, '0'))::uuid,
    'Przepis A7 ' || g,
    'a7-przepis-' || g,
    'public', 'published', 'own', now() - (g || ' seconds')::interval, now(), now()
FROM generate_series(1, 40000) AS g;

INSERT INTO posts (id, author_id, body, visibility, status, recipe_id, published_at, created_at, updated_at)
SELECT
    ('00000003-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    ('00000001-0000-0000-0000-' || lpad((((g - 1) % 10000) + 1)::text, 12, '0'))::uuid,
    'Syntetyczny wpis A7 ' || g,
    'public', 'published',
    CASE WHEN g % 5 = 0 THEN
        ('00000002-0000-0000-0000-' || lpad((((g - 1) % 40000) + 1)::text, 12, '0'))::uuid
    ELSE NULL END,
    now() - (g || ' seconds')::interval, now(), now()
FROM generate_series(1, 80000) AS g;

INSERT INTO media (id, owner_id, disk, object_key, mime_type, bytes, width, height, status, metadata, created_at, updated_at)
SELECT
    ('00000004-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    ('00000001-0000-0000-0000-' || lpad(((((g - 1) % 80000) % 10000) + 1)::text, 12, '0'))::uuid,
    'public',
    'a7/original/' || g || '.jpg',
    'image/webp', 123456, 1600, 1067, 'ready',
    jsonb_build_object('variants', jsonb_build_object(
        'feed', jsonb_build_object('key', 'a7/variants/' || g || '/feed.webp', 'width', 960, 'height', 640)
    )),
    now(), now()
FROM generate_series(1, 250000) AS g;

INSERT INTO post_media (post_id, media_id, position)
SELECT
    ('00000003-0000-0000-0000-' || lpad((((g - 1) % 80000) + 1)::text, 12, '0'))::uuid,
    ('00000004-0000-0000-0000-' || lpad(g::text, 12, '0'))::uuid,
    floor((g - 1) / 80000.0)::smallint
FROM generate_series(1, 250000) AS g;

ANALYZE users;
ANALYZE profiles;
ANALYZE follows;
ANALYZE recipes;
ANALYZE posts;
ANALYZE media;
ANALYZE post_media;
SQL);

$skala = [
    'users' => (int) DB::table('users')->count(),
    'recipes' => (int) DB::table('recipes')->count(),
    'posts' => (int) DB::table('posts')->count(),
    'media' => (int) DB::table('media')->count(),
    'post_media' => (int) DB::table('post_media')->count(),
    'follows_viewer' => (int) DB::table('follows')->where('follower_id', uuidA7(1, 1))->count(),
];

$aktywnaFaza = null;
$zebrane = [];
DB::listen(function (QueryExecuted $q) use (&$aktywnaFaza, &$zebrane): void {
    if ($aktywnaFaza === null) {
        return;
    }
    $zebrane[$aktywnaFaza][] = [
        'sql' => $q->sql,
        'bindings' => $q->bindings,
        'db_ms' => $q->time,
    ];
});

$viewer = User::query()->findOrFail(uuidA7(1, 1));
$following = app(FollowingFeed::class);
$discover = app(DiscoverFeed::class);

$wyniki = [];
$ostatniaStrona = null;
foreach ([
    'following' => fn () => $following->paginate($viewer, 15),
    'discover_guest' => fn () => $discover->paginate(null, 15),
    'discover_user' => fn () => $discover->paginate($viewer, 15),
] as $nazwa => $akcja) {
    $proby = [];
    $ostatnieZapytania = [];

    // Pierwszy przebieg rozgrzewkowy nie wchodzi do mediany.
    $aktywnaFaza = $nazwa.'_warmup';
    $akcja();
    $aktywnaFaza = null;

    for ($i = 1; $i <= 5; $i++) {
        $faza = $nazwa.'_run_'.$i;
        $zebrane[$faza] = [];
        $aktywnaFaza = $faza;
        $start = hrtime(true);
        $strona = $akcja();
        $wallMs = (hrtime(true) - $start) / 1_000_000;
        $aktywnaFaza = null;

        if ($nazwa === 'following') {
            $ostatniaStrona = $strona;
        }

        $zapytania = $zebrane[$faza];
        $ostatnieZapytania = $zapytania;
        $proby[] = [
            'wall_ms' => round($wallMs, 3),
            'queries' => count($zapytania),
            'db_ms' => round(array_sum(array_column($zapytania, 'db_ms')), 3),
            'items' => $strona->count(),
        ];
    }

    $wyniki[$nazwa] = [
        'runs' => $proby,
        'median_wall_ms' => mediana(array_column($proby, 'wall_ms')),
        'median_queries' => mediana(array_column($proby, 'queries')),
        'median_db_ms' => mediana(array_column($proby, 'db_ms')),
        'last_queries' => $ostatnieZapytania,
        'explain_analyze_buffers' => planDla($ostatnieZapytania),
    ];
}

// -------------------------------------------------------------------------
// Rzeczywista trasa /zdjecia dla WSZYSTKICH obrazów z jednej strony feedu.
// Dysk jest lokalny: mierzymy koszt Laravela + bazy/autoryzacji, nie sieć R2.
// -------------------------------------------------------------------------
if ($ostatniaStrona === null) {
    throw new RuntimeException('Brak strony following do pomiaru zdjęć.');
}

$mediaIds = [];
foreach ($ostatniaStrona->items() as $post) {
    foreach ($post->media as $media) {
        $mediaIds[] = $media->getKey();
        $nr = (int) substr((string) $media->getKey(), -12);
        Storage::disk('public')->put("a7/variants/{$nr}/feed.webp", 'A7');
    }
}
$mediaIds = array_values(array_unique($mediaIds));

$http = app(HttpKernel::class);
$zebrane['media_page_guest'] = [];
$aktywnaFaza = 'media_page_guest';
$start = hrtime(true);
$statusy = [];
foreach ($mediaIds as $id) {
    $request = Request::create('/zdjecia/'.$id.'/feed', 'GET');
    $response = $http->handle($request);
    $statusy[] = $response->getStatusCode();
    $http->terminate($request, $response);
}
$mediaWallMs = (hrtime(true) - $start) / 1_000_000;
$aktywnaFaza = null;

$mediaQueries = $zebrane['media_page_guest'];
$mediaNaZadanie = count($mediaIds) > 0 ? count($mediaQueries) / count($mediaIds) : 0.0;

$wynik = [
    'generated_at_utc' => gmdate('c'),
    'database' => $baza,
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'scale' => $skala,
    'feeds' => $wyniki,
    'media_route_guest_feed_page' => [
        'images' => count($mediaIds),
        'statuses' => array_count_values($statusy),
        'queries_total' => count($mediaQueries),
        'queries_per_image' => round($mediaNaZadanie, 3),
        'db_ms_total' => round(array_sum(array_column($mediaQueries, 'db_ms')), 3),
        'wall_ms_total' => round($mediaWallMs, 3),
        'queries' => $mediaQueries,
        'note' => 'Dysk lokalny: wynik obejmuje Laravel, route-model binding, autoryzację i bazę; nie obejmuje czasu podpisania/pobrania z R2.',
    ],
];

$sciezka = dirname(__DIR__).'/dowody-a7/wydajnosc-wynik.json';
file_put_contents($sciezka, json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

echo json_encode([
    'scale' => $skala,
    'following' => [
        'median_wall_ms' => $wyniki['following']['median_wall_ms'],
        'median_queries' => $wyniki['following']['median_queries'],
        'median_db_ms' => $wyniki['following']['median_db_ms'],
    ],
    'discover_guest' => [
        'median_wall_ms' => $wyniki['discover_guest']['median_wall_ms'],
        'median_queries' => $wyniki['discover_guest']['median_queries'],
        'median_db_ms' => $wyniki['discover_guest']['median_db_ms'],
    ],
    'discover_user' => [
        'median_wall_ms' => $wyniki['discover_user']['median_wall_ms'],
        'median_queries' => $wyniki['discover_user']['median_queries'],
        'median_db_ms' => $wyniki['discover_user']['median_db_ms'],
    ],
    'media_page_guest' => [
        'images' => count($mediaIds),
        'queries_total' => count($mediaQueries),
        'queries_per_image' => round($mediaNaZadanie, 3),
        'db_ms_total' => round(array_sum(array_column($mediaQueries, 'db_ms')), 3),
        'wall_ms_total' => round($mediaWallMs, 3),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
