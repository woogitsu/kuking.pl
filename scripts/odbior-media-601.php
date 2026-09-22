<?php

declare(strict_types=1);

use App\Domain\Media\OrientacjaZdjecia;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\Support\LicznikDekodowanGd;

// Sonda wyłącznie dla własnego runtime floty, nigdy dla produkcji.
$root = dirname(__DIR__);
$database = 'kuking_flota_gpt-odbior-wdrozen';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '55439' || getenv('DB_DATABASE') !== $database
    || realpath($root) !== '/home/mateusz/flota/gpt-odbior-wdrozen-run') {
    throw new RuntimeException('Uruchom sondę we własnym runtime na bazie floty i porcie 55439.');
}

require $root.'/vendor/autoload.php';
require $root.'/tests/Support/LicznikDekodowanGd.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// To samodzielny skrypt, nie Artisan: błąd asercji MUSI zakończyć proces kodem 1.
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

check(config('database.connections.pgsql.host') === '127.0.0.1'
    && (string) config('database.connections.pgsql.port') === '55439'
    && config('database.connections.pgsql.database') === $database,
    'Konfiguracja aplikacji wskazuje inną bazę niż izolowana baza sondy.');
check(! is_link($root.'/vendor'), 'Skopiuj vendor zamiast dowiązania.');
check(realpath((new ReflectionClass(ProcessUploadedImage::class))->getFileName())
    === realpath($root.'/app/Jobs/ProcessUploadedImage.php'), 'Autoloader ładuje inny job.');

$name = $argv[1] ?? '';
$photoDirectory = $argv[2] ?? '/home/mateusz/kuking-601-photos';
$sources = json_decode(file_get_contents($root.'/docs/infra/evidence/media601/photo-sources.json'), true, flags: JSON_THROW_ON_ERROR);
$source = array_values(array_filter($sources, fn (array $entry): bool => $entry['name'] === $name))[0] ?? null;
check($source !== null, 'Wybierz fotografię 12mp, 24mp albo 48mp z manifestu.');
$path = $photoDirectory.'/'.$name.'.jpg';
check(is_file($path) && hash_file('sha256', $path) === $source['sha256'], 'Fotografia nie zgadza się z manifestem SHA256.');
$original = file_get_contents($path);
[$width, $height] = getimagesize($path);
$exif = exif_read_data($path);
$orientation = (int) ($exif['Orientation'] ?? 1);

config([
    'queue.default' => 'database',
    'mail.default' => 'array',
    'cache.default' => 'array',
    'logging.default' => 'single',
    'filesystems.disks.odbior601' => [
        'driver' => 'local', 'root' => storage_path('app/odbior601'), 'throw' => true,
    ],
]);
check(DB::table('jobs')->where('queue', 'media')->count() === 0, 'W kolejce media czeka inne zadanie.');
$failedBefore = DB::table('failed_jobs')->count();
$owner = User::factory()->create();
$key = 'incoming/odbior601/'.$owner->id.'/'.$name.'.jpg';
$disk = Storage::disk('odbior601');
$media = null;
$jobId = null;
$processed = [];
Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$processed): void {
    $processed[] = ['job_id' => $event->job->getJobId(), 'class' => $event->job->resolveName()];
});

try {
    check($disk->put($key, $original), 'Nie udało się zapisać lokalnego oryginału.');
    $media = Media::create([
        'owner_id' => $owner->id, 'disk' => 'odbior601', 'variants_disk' => 'odbior601',
        'object_key' => $key, 'status' => Media::STATUS_PENDING,
        'metadata' => ['exif_orientation' => $orientation],
    ]);
    $jobId = Queue::connection('database')->push(new ProcessUploadedImage($media->id), '', 'media');
    check(DB::table('jobs')->where('id', $jobId)->exists(), 'Zadanie nie trafiło do kolejki bazodanowej.');
    LicznikDekodowanGd::$liczba = 0;
    $before = getrusage();
    $start = hrtime(true);
    $exit = Artisan::call('queue:work', [
        'connection' => 'database', '--queue' => 'media', '--once' => true,
        '--tries' => 1, '--timeout' => 120, '--memory' => 700, '--no-interaction' => true,
    ]);
    $wallMs = (hrtime(true) - $start) / 1e6;
    $after = getrusage();
    $cpuMs = (($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'])
        - ($before['ru_utime.tv_sec'] + $before['ru_stime.tv_sec'])) * 1000
        + (($after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'])
            - ($before['ru_utime.tv_usec'] + $before['ru_stime.tv_usec'])) / 1000;
    $workerLog = Artisan::output();
    $decodes = LicznikDekodowanGd::$liczba;
    $media->refresh();
    check($exit === 0 && count($processed) === 1 && (string) $processed[0]['job_id'] === (string) $jobId,
        'Worker nie potwierdził wykonania właściwego zadania.');
    check($decodes === 1, 'Job powinien zdekodować fotografię dokładnie raz.');
    check($media->status === Media::STATUS_READY, 'Zdjęcie nie osiągnęło ready.');
    check(! DB::table('jobs')->where('id', $jobId)->exists(), 'Zadanie zostało w kolejce.');
    check(DB::table('failed_jobs')->count() === $failedBefore, 'Przybyło nieudanych zadań.');
    check(! isset($media->metadata[Media::METADANE_WARIANTY_W_TRAKCIE]), 'Po sukcesie zostały klucze w trakcie.');
    check(count($media->metadata['variants']) === 3, 'Brakuje wariantu zdjęcia.');

    // Baseline po pomiarze workera: jego alokacje NIE zawyżają raportowanego RSS joba.
    $manager = ImageManager::gd(autoOrientation: false);
    LicznikDekodowanGd::$liczba = 0;
    $variants = [];
    foreach (config('kuking.media.variants') as $variant => $edge) {
        $image = $manager->read($original);
        OrientacjaZdjecia::zastosuj($image, $orientation);
        $image->scaleDown(width: $edge, height: $edge);
        $expected = (string) $image->toWebp(quality: 82);
        $actual = $media->wariant($variant);
        $bytes = $disk->get($actual['key']);
        $size = getimagesizefromstring($bytes);
        check($expected === $bytes, 'Wariant '.$variant.' różni się bajtowo od trzech dekodowań.');
        check([$actual['width'], $actual['height']] === [$image->width(), $image->height()]
            && [$size[0], $size[1]] === [$actual['width'], $actual['height']]
            && $actual['bytes'] === strlen($bytes) && $size['mime'] === 'image/webp',
            'Wymiary, format albo liczba bajtów wariantu nie zgadzają się.');
        $variants[$variant] = [
            'width' => $size[0], 'height' => $size[1], 'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'baseline_identical' => true,
        ];
        unset($image);
    }
    check(LicznikDekodowanGd::$liczba === 3, 'Kontrola dodatnia licznika nie wykryła trzech dekodowań baseline.');
    echo json_encode([
        'utc' => gmdate('c'), 'photo' => $name, 'source_sha256' => $source['sha256'],
        'source_width' => $width, 'source_height' => $height, 'orientation' => $orientation,
        'database' => DB::selectOne('select current_database() as name, current_user as owner, inet_server_port() as port'),
        'php' => PHP_VERSION, 'gd' => gd_info(), 'job_source_sha256' => hash_file('sha256', $root.'/app/Jobs/ProcessUploadedImage.php'),
        'media_id' => $media->id, 'status' => $media->status, 'processed_at' => $media->metadata['processed_at'],
        'job_decodes' => $decodes, 'baseline_decodes' => LicznikDekodowanGd::$liczba,
        'wall_ms' => round($wallMs, 2), 'cpu_ms' => round($cpuMs, 2), 'peak_rss_kib' => $after['ru_maxrss'],
        'worker_log' => $workerLog, 'processed_events' => $processed,
        'variants' => $variants, 'result' => 'PASS',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    // Wyłącznie rekordy i pliki utworzone przez tę sondę, bez kasowania całej bazy.
    foreach (array_keys(config('kuking.media.variants')) as $variant) {
        $disk->delete(Media::kluczPublicznegoWariantu($key, $variant));
    }
    $disk->delete($key);
    if ($jobId !== null) {
        DB::table('jobs')->where('id', $jobId)->delete();
    }
    if ($media !== null) {
        DB::table('media')->where('id', $media->id)->delete();
    }
    DB::table('profiles')->where('user_id', $owner->id)->delete();
    DB::table('users')->where('id', $owner->id)->delete();
}
