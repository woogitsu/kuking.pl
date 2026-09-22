<?php

declare(strict_types=1);
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Przyrząd lokalny: nie jest komendą produkcyjną ani trasą aplikacji.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Handler konsolowy frameworka poza Artisanem potrafi wypisać wyjątek i
// zakończyć proces kodem 0. Przyrząd musi odmówić także kodem wyjścia.
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Przerwij pomiar i sprawdź przygotowanie runtime: '.get_class($error).' w linii '.$error->getLine().PHP_EOL);
    exit(1);
});
$db = DB::connection();
if ($app->environment('production') || $db->getConfig('host') !== '127.0.0.1'
    || (string) $db->getConfig('port') !== '55439'
    || $db->getDatabaseName() !== 'kuking_flota_gpt-monitoring') {
    fwrite(STDERR, 'Użyj własnej bazy kuking_flota_gpt-monitoring na 127.0.0.1:55439.'.PHP_EOL);
    exit(2);
}
// Nigdy nie pozwalamy, by lokalna próba użyła zewnętrznego transportu.
config(['logging.channels.blad_webhook.url' => null, 'mail.default' => 'array']);
Http::preventStrayRequests();
$mode = $argv[1] ?? '';
if ($mode === 'check') {
    echo "Potwierdzono własną bazę stanowiska i port 55439.\n";
} elseif ($mode === 'alerts') {
    require __DIR__.'/alerts.php';
} elseif ($mode === 'sample') {
    $pdo = $db->getPdo();
    $query = $pdo->prepare("SELECT count(*) AS total, count(*) FILTER (WHERE state='active') AS active,
        count(*) FILTER (WHERE state='idle') AS idle FROM pg_stat_activity
        WHERE datname=current_database() AND backend_type='client backend' AND pid<>pg_backend_pid()");
    for ($i = 0; $i < 12000; $i++) {
        // Każde zapytanie ma własną transakcję: żadnego zamrożonego snapshotu.
        $query->execute();
        echo json_encode(['time' => microtime(true)] + $query->fetch(PDO::FETCH_ASSOC)).PHP_EOL;
        usleep(5000);
    }
} elseif ($mode === 'hold') {
    $db->select('SELECT pg_sleep(2)');
} elseif ($mode === 'seed') {
    if (User::count() !== 0) {
        throw new RuntimeException('Przygotuj pustą izolowaną bazę pomiaru.');
    }
    $user = User::factory()->create();
    Post::factory()->count(200)->create(['author_id' => $user->id]);
    $recipes = Recipe::factory()->count(40)->create(['author_id' => $user->id]);
    file_put_contents(storage_path('monitoring-routes.json'), json_encode([
        '/odkryj', '/szukaj?q=pierogi', '/przepisy/'.$recipes->first()->slug,
    ], JSON_THROW_ON_ERROR));
    $im = imagecreatetruecolor(2400, 1600);
    ob_start();
    imagejpeg($im);
    $bytes = ob_get_clean();
    for ($i = 0; $i < 12; $i++) {
        $key = 'monitoring/source-'.$i.'.jpg';
        Storage::disk('local')->put($key, $bytes);
        $media = Media::factory()->pending()->create([
            'owner_id' => $user->id, 'disk' => 'local', 'object_key' => $key,
            'mime_type' => 'image/jpeg', 'width' => 2400, 'height' => 1600, 'bytes' => strlen($bytes),
        ]);
        ProcessUploadedImage::dispatch($media->id);
    }
    echo "Przygotowano 200 wpisów, 40 przepisów i 12 zdjęć 3,84 Mpx.\n";
} elseif ($mode === 'scheduler') {
    // Prawdziwy schedule:run, ze stałą minutą uruchamiającą czujkę i liczniki.
    Carbon::setTestNow('2026-09-20 12:25:00 UTC');
    exit(Artisan::call('schedule:run'));
} elseif ($mode === 'verify') {
    echo json_encode([
        'ready' => Media::where('status', 'ready')->count(),
        'jobs' => $db->table('jobs')->count(), 'failed' => $db->table('failed_jobs')->count(),
        'settings' => $db->select("SELECT name,setting FROM pg_settings WHERE name IN ('max_connections','superuser_reserved_connections','reserved_connections','server_version')"),
    ], JSON_PRETTY_PRINT).PHP_EOL;
} else {
    throw new InvalidArgumentException('Wybierz check, alerts, sample, hold, seed, scheduler albo verify.');
}
