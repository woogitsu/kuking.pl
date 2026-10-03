<?php

declare(strict_types=1);

/** Dwa rzeczywiste procesy: GET zimnego kanału i modelowe ukrycie wpisu. */

use App\Models\Post;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', (string) DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza bazą wyścigów.');
}

DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '30s'");
DB::statement("SET idle_in_transaction_session_timeout = '30s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    if ($argv[1] === 'kanal') {
        $zatrzymany = false;
        DB::listen(static function (QueryExecuted $query) use (&$zatrzymany, $args): void {
            $sql = strtolower($query->sql);
            if ($zatrzymany || ! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, '"posts"')) {
                return;
            }

            $zatrzymany = true;
            DB::selectOne('SELECT pg_advisory_xact_lock(28630, hashtext(?))', [$args['barrier']]);
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create($args['url'], 'GET'));
        $result = [
            'status' => $response->getStatusCode(),
            'xml' => (string) $response->getContent(),
            'etag' => (string) $response->headers->get('ETag'),
            'bariera' => $zatrzymany,
        ];
    } else {
        Post::query()->findOrFail($args['post'])->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        $result = 'ukryto';
    }

    echo json_encode(['ok' => true, 'wartosc' => $result, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'komunikat' => $exception->getMessage(), 'wyjatek' => $exception::class,
    ]);
}
