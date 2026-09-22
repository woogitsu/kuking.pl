<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Przygotowanie danych przerwane: '.$error->getMessage().PHP_EOL);
    exit(1);
});
$db = DB::connection();
if ($app->environment('production') || $db->getDriverName() !== 'pgsql' || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55439' || $db->getDatabaseName() !== 'kuking_585_benchmark') {
    throw new RuntimeException('Generator wymaga lokalnej bazy kuking_585_benchmark na 127.0.0.1:55439.');
}
if (User::where('email', 'like', 'scale585-%')->exists()) {
    throw new RuntimeException('Dane pomiarowe już istnieją. Sprawdź je; generator ich nie zastępuje.');
}
$result = $db->transaction(function () use ($db) {
    $hash = Hash::make(bin2hex(random_bytes(24)));
    $authors = [];
    for ($i = 0; $i < 2100; $i++) {
        $authors[] = User::factory()->create(['email' => "scale585-author-$i@example.test", 'password' => $hash, 'wants_weekly_digest' => false])->id;
    }
    $viewers = [];
    foreach ([10, 100, 500, 2000] as $count) {
        $viewer = User::factory()->create(['email' => "scale585-viewer-$count@example.test", 'password' => $hash, 'wants_weekly_digest' => false]);
        $viewer->following()->attach(array_slice($authors, 0, $count), ['created_at' => '2026-01-01 00:00:00+00']);
        $viewers[$count] = $viewer->id;
    }
    $rows = [];
    for ($i = 0; $i < 50000; $i++) {
        $author = $i % 10 < 6 ? $authors[$i % 20] : $authors[$i % 2100];
        $time = gmdate('Y-m-d H:i:s', 1767225600 + intdiv($i, 5));
        $rows[] = ['id' => (string) Str::uuid(), 'author_id' => $author, 'body' => 'Syntetyczny wpis pomiarowy 585 numer '.$i, 'visibility' => 'public', 'status' => 'published', 'published_at' => $time, 'created_at' => $time, 'updated_at' => $time];
        if (count($rows) === 500) {
            $db->table('posts')->insert($rows);
            $rows = [];
        }
    }

    return ['authors' => count($authors), 'posts' => 50000, 'viewers' => $viewers, 'scope' => 'syntetyczne publiczne wpisy; bez mediów, przepisów i komentarzy'];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
