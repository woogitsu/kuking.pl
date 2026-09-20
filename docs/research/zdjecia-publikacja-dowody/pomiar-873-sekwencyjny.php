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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55439' || config('database.connections.pgsql.database') !== 'kuking_flota_gpt-zdjecia-publikacja') {
    throw new RuntimeException('Odmowa: baza nie należy do pomiaru.');
}
config(['kuking.questions.enabled' => true, 'session.driver' => 'array', 'cache.default' => 'array', 'kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
DB::statement("SET statement_timeout = '20s'");
DB::statement("SET lock_timeout = '10s'");

$result = [];
foreach (['posts', 'questions', 'cooked'] as $form) {
    $author = User::factory()->create();
    $cook = User::factory()->create();
    $recipe = Recipe::factory()->create(['author_id' => $author->id]);
    $key = (string) Str::uuid();
    $dir = storage_path('app/pomiar-873-'.$key);
    mkdir($dir, 0700, true);
    config(['filesystems.disks.public.root' => $dir]);
    Storage::forgetDisk('public');
    Queue::fake();
    Auth::login($cook);
    $url = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
    foreach ([1, 2] as $attempt) {
        $request = Request::create($url, 'POST', [
            'klucz_wyslania' => $key, 'body' => 'Pomiar kolejnego obiadu',
            'title' => 'Jak ugotować dobry obiad?', 'visibility' => 'public',
        ], [], ['photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)]]);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $result[$form][$attempt] = [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'jobs' => Queue::pushed(ProcessUploadedImage::class)->count(),
            'media' => Media::where('owner_id', $cook->id)->count(),
            'files' => count(Storage::disk('public')->allFiles()),
            'results' => $form === 'cooked' ? CookedEvent::where('user_id', $cook->id)->count() : Post::where('author_id', $cook->id)->count(),
            'notifications' => Notification::where('user_id', $author->id)->where('type', Notification::TYPE_COOKED)->count(),
        ];
        $kernel->terminate($request, $response);
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
