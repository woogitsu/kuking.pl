<?php

declare(strict_types=1);

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Wyłącznie lokalny przyrząd; nie jest trasą ani komendą aplikacji.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Sprawdź przygotowanie pomiaru: '.get_class($error).' w linii '.$error->getLine().PHP_EOL);
    exit(1);
});
$db = DB::connection();
if ($app->environment('production') || $db->getConfig('host') !== '127.0.0.1'
    || (string) $db->getConfig('port') !== '55439'
    || $db->getDatabaseName() !== 'kuking_flota_gpt-redis-ha'
    || $db->getConfig('username') !== 'kuking') {
    fwrite(STDERR, "Użyj własnej bazy kuking_flota_gpt-redis-ha, użytkownika kuking i portu 55439.\n");
    exit(2);
}
config(['logging.channels.blad_webhook.url' => null, 'mail.default' => 'array',
    'cache.default' => 'database', 'session.driver' => 'database',
    'queue.default' => 'database', 'session.lottery' => [0, 100]]);
Http::preventStrayRequests();
$mode = $argv[1] ?? 'check';
if ($mode === 'check') {
    echo json_encode($db->selectOne('SELECT current_database() AS db, current_user AS owner, inet_server_port() AS port')).PHP_EOL;
    exit;
}
if ($mode === 'seed') {
    if (User::count() !== 0) {
        throw new RuntimeException('Przygotuj pustą własną bazę.');
    }
    $user = User::factory()->create();
    Post::factory()->count(200)->create(['author_id' => $user->id, 'body' => 'Domowe pierogi na obiad.']);
    $recipes = Recipe::factory()->count(40)->create(['author_id' => $user->id]);
    file_put_contents(storage_path('infra603-routes.json'), json_encode(['/odkryj', '/szukaj?q=pierogi', '/przepisy/'.$recipes->first()->slug]));
    echo "200 wpisów, 40 przepisów, 1 konto testowe.\n";
    exit;
}
if ($mode === 'queue-size') {
    $count = (int) ($argv[2] ?? 0);
    if (! in_array($count, [0, 1000, 10000, 100000], true)) {
        throw new InvalidArgumentException('Wybierz rozmiar z planu próby.');
    }
    // Tylko syntetyczna kolejka własnej bazy. Zadania odłożone o dobę,
    // payload nie jest wykonywany. Nie udajemy realnej mieszanki jobów.
    $db->table('jobs')->truncate();
    $db->insert("INSERT INTO jobs(queue,payload,attempts,reserved_at,available_at,created_at)
        SELECT 'high', repeat('x',1024), 0, NULL, ?, ? FROM generate_series(1, ?)", [time() + 86400, time(), $count]);
    $db->statement('ANALYZE jobs');
    echo json_encode(['rows' => $count, 'bytes' => $db->selectOne("SELECT pg_total_relation_size('jobs') AS n")->n]).PHP_EOL;
    exit;
}

$samples = [];
$enabled = true;
$classify = static function (string $sql): string {
    foreach (['cache_locks' => 'locks', 'cache' => 'cache', 'jobs' => 'queue',
        'failed_jobs' => 'queue', 'job_batches' => 'queue', 'sessions' => 'sessions'] as $table => $category) {
        if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql)) {
            return $category;
        }
    }

    return 'business';
};
DB::listen(function (QueryExecuted $query) use (&$samples, &$enabled, $classify): void {
    if ($enabled) {
        // Nigdy nie zapisujemy SQL, bindings, kluczy sesji ani treści danych.
        $samples[$classify($query->sql)][] = $query->time;
    }
});
$start = hrtime(true);
$details = [];
if ($mode === 'http') {
    $routes = json_decode(file_get_contents(storage_path('infra603-routes.json')), true);
    $index = (int) ($argv[2] ?? 0);
    if (! isset($routes[$index])) {
        throw new InvalidArgumentException('Wybierz istniejącą trasę.');
    }
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('http://localhost'.$routes[$index]);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $details = ['route_index' => $index, 'status' => $response->getStatusCode(), 'html_bytes' => strlen($response->getContent())];
    if ($details['status'] !== 200 || $details['html_bytes'] < 1000) {
        throw new RuntimeException('Żądanie nie doszło do działającej strony.');
    }
} elseif ($mode === 'idle-worker') {
    // Te same cztery kolejki i sleep co w entrypoincie, ograniczony czas.
    $details['exit'] = Artisan::call('queue:work', ['--queue' => 'high,default,media,low', '--sleep' => 1,
        '--max-time' => 10, '--no-interaction' => true]);
} elseif ($mode === 'queue-pop') {
    for ($i = 0; $i < 200; $i++) {
        if (Queue::connection('database')->pop('high') !== null) {
            throw new RuntimeException('Syntetyczne zadanie nie powinno być gotowe.');
        }
    }
} elseif ($mode === 'media') {
    $enabled = false;
    $picture = imagecreatetruecolor(2400, 1600);
    ob_start();
    imagejpeg($picture);
    $bytes = ob_get_clean();
    for ($i = 0; $i < 6; $i++) {
        $key = 'infra603/source-'.$i.'.jpg';
        Storage::disk('local')->put($key, $bytes);
        $media = Media::factory()->pending()->create([
            'owner_id' => User::first()->id, 'disk' => 'local', 'object_key' => $key,
            'mime_type' => 'image/jpeg', 'width' => 2400, 'height' => 1600, 'bytes' => strlen($bytes),
        ]);
        ProcessUploadedImage::dispatch($media->id);
    }
    $enabled = true;
    $start = hrtime(true);
    $details['exit'] = Artisan::call('queue:work', ['--queue' => 'media', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
    $enabled = false;
    $details['ready'] = Media::where('status', 'ready')->count();
    $details['failed'] = $db->table('failed_jobs')->count();
    if ($details['ready'] !== 6 || $details['failed'] !== 0) {
        throw new RuntimeException('Worker nie zakończył sześciu zdjęć.');
    }
} elseif ($mode === 'gc') {
    $enabled = false;
    $db->insert("INSERT INTO sessions(id,payload,last_activity) SELECT 'infra603-expired-' || i, 'x', ? FROM generate_series(1,2000) i", [time() - 86400]);
    $db->insert("INSERT INTO cache(key,value,expiration) SELECT ? || 'infra603-expired-' || i, 'i:1;', ? FROM generate_series(1,2000) i", [config('cache.prefix'), time() - 86400]);
    $enabled = true;
    $start = hrtime(true);
    $details['sessions_deleted'] = $app->make('session')->driver()->getHandler()->gc(7200);
    // DatabaseStore usuwa wygasłe klucze przy odczycie; nie ma tu wymyślonego
    // okresowego cache:prune. Klucze są syntetyczne i należą do przyrządu.
    $keys = array_map(fn ($i) => 'infra603-expired-'.$i, range(1, 2000));
    Cache::many($keys);
    $enabled = false;
    $details['cache_remaining'] = $db->table('cache')->where('key', 'like', '%infra603-expired-%')->count();
    if ($details['sessions_deleted'] !== 2000 || $details['cache_remaining'] !== 0) {
        throw new RuntimeException('Sprzątanie nie usunęło kontrolnych rekordów.');
    }
} elseif ($mode === 'operations') {
    for ($i = 0; $i < 200; $i++) {
        Cache::put('infra603-key', 1, 60);
        Cache::get('infra603-key');
        $lock = Cache::lock('infra603-lock', 10);
        if (! $lock->get()) {
            throw new RuntimeException('Nie zdobyto blokady kontrolnej.');
        }
        $lock->release();
    }
} elseif ($mode === 'business') {
    // Stałe zapytanie kontrolne, a nie model całego ruchu portalu.
    for ($i = 0; $i < 1000; $i++) {
        Post::query()->where('status', 'published')->where('visibility', 'public')
            ->orderByDesc('published_at')->orderByDesc('id')->limit(20)->get();
    }
} elseif ($mode === 'infra-load') {
    $until = microtime(true) + 12;
    $iterations = 0;
    while (microtime(true) < $until) {
        Cache::put('infra603-load-'.getmypid(), $iterations++, 60);
        Cache::get('infra603-load-'.getmypid());
        $lock = Cache::lock('infra603-load-lock-'.getmypid(), 10);
        if ($lock->get()) {
            $lock->release();
        }
        Queue::connection('database')->pop('high');
    }
    $details['iterations'] = $iterations;
} else {
    throw new InvalidArgumentException('Wybierz tryb pomiaru.');
}
$elapsed = (hrtime(true) - $start) / 1e9;
$enabled = false;
echo json_encode(['mode' => $mode, 'seconds' => $elapsed, 'details' => $details,
    'sql_ms' => $samples], JSON_THROW_ON_ERROR).PHP_EOL;
