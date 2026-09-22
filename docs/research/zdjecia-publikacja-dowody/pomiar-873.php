<?php

declare(strict_types=1);
// Pomiar dwóch procesów HTTP, poza transakcją testów. Wyłącznie własna baza floty.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Jobs\ProcessUploadedImage;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55439' || config('database.connections.pgsql.database') !== 'kuking_flota_gpt-zdjecia-publikacja') {
    throw new RuntimeException('Odmowa: baza nie należy do pomiaru.');
}
config(['kuking.questions.enabled' => true, 'session.driver' => 'array', 'cache.default' => 'array', 'kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
DB::statement("SET statement_timeout = '20s'");
DB::statement("SET lock_timeout = '10s'");
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    config(['filesystems.disks.public.root' => $data['dir'].'/files']);
    Queue::fake();
    Auth::loginUsingId($data['user']);
    Media::created(function () use ($data) {
        file_put_contents($data['dir'].'/'.$data['slot'].'.ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
        $deadline = microtime(true) + 15;
        while (! file_exists($data['dir'].'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Brak drugiego uczestnika.');
            }
            usleep(10000);
        }
    });
    $request = Request::create($data['url'], 'POST', [
        'klucz_wyslania' => $data['key'], 'body' => 'Pomiar równoczesnego obiadu',
        'title' => 'Jak ugotować dobry obiad?', 'visibility' => 'public',
    ], [], ['photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)]]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'jobs' => Queue::pushed(ProcessUploadedImage::class)->count()]);
    $kernel->terminate($request, $response);
    exit;
}
$result = [];
foreach (['posts', 'questions', 'cooked'] as $form) {
    $author = User::factory()->create();
    $cook = User::factory()->create();
    $recipe = Recipe::factory()->create(['author_id' => $author->id]);
    $key = (string) Str::uuid();
    $dir = storage_path('app/pomiar-873-'.$key);
    mkdir($dir, 0700, true);
    $url = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
    $children = [];
    foreach (['a', 'b'] as $slot) {
        $data = ['dir' => $dir, 'slot' => $slot, 'user' => $cook->id, 'url' => $url, 'key' => $key];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($data)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    try {
        $deadline = microtime(true) + 18;
        while (! file_exists($dir.'/a.ready') || ! file_exists($dir.'/b.ready')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Procesy nie dotarły do bariery.');
            }
            usleep(10000);
        }
        $pids = [trim(file_get_contents($dir.'/a.ready')), trim(file_get_contents($dir.'/b.ready'))];
        if ($pids[0] === $pids[1]) {
            throw new RuntimeException('Połączenia nie są niezależne.');
        }
        touch($dir.'/release');
        $responses = [];
        foreach ($children as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException($stderr.$stdout);
            }
            $responses[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        }
        $rows = $form === 'cooked' ? CookedEvent::where('user_id', $cook->id)->count() : Post::where('author_id', $cook->id)->count();
        $files = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/files', FilesystemIterator::SKIP_DOTS)));
        $result[$form] = ['pids' => $pids, 'responses' => $responses, 'results' => $rows, 'media' => Media::where('owner_id', $cook->id)->count(), 'files' => $files, 'notifications' => Notification::where('user_id', $author->id)->where('type', Notification::TYPE_COOKED)->count()];
    } finally {
        touch($dir.'/release');
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
