<?php

declare(strict_types=1);

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::connection()->getDatabaseName(), 'kuking_race')) {
    throw new RuntimeException('Odmowa: to nie jest baza grupy dwa-polaczenia.');
}
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '20s'");
config(['kuking.community.host_user_id' => $argv[2], 'queue.default' => 'database']);
echo 'PID='.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL;
flush();
Notification::creating(function (Notification $notification) use ($argv): void {
    if ((int) $argv[4] !== 0 && $notification->type === Notification::TYPE_FIRST_POST) {
        DB::select('select pg_advisory_lock(?)', [(int) $argv[4]]);
        DB::select('select pg_advisory_unlock(?)', [(int) $argv[4]]);
    }
});
$post = app(PublishPost::class)->handle(User::findOrFail($argv[1]), 'Równoległy rosół.', kluczWyslania: $argv[3]);
echo 'DONE='.$post->id.PHP_EOL;
