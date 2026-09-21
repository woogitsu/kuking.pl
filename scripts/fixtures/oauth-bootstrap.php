<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/baza-pomiarowa.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(function (Throwable $e): never {
    if (PHP_SAPI === 'cli-server') {
        http_response_code(500);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'OAUTH345: '.$e->getMessage().PHP_EOL);
    } else {
        error_log('OAUTH345: '.$e->getMessage());
    }
    exit(1);
});
$c = DB::connection();
if (! $app->environment('local') || ! in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || $c->getDriverName() !== 'pgsql'
    // Patrz `scripts/fixtures/baza-pomiarowa.php`: rodzina baz jednorazowych
    // jest ta sama, co po stronie `scripts/bezpiecznik-bazy.mjs`.
    || ! kukingWolnoUzycBazyFixture($c->getDatabaseName(), ['kuking_oauth345', 'kuking_test_a11y', 'kuking_a11y'])
    || ! in_array($c->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || ! getenv('DB_PORT') || (string) $c->getConfig('port') !== (string) getenv('DB_PORT')
    || config('mail.default') !== 'array') {
    throw new RuntimeException('OAUTH345: wymagane local, lokalny PostgreSQL kuking_, jawny port i mailer array.');
}
// Brak wyjścia HTTP także dla nieprzewidzianych wywołań aplikacji.
Http::preventStrayRequests();
config(['logging.default' => 'single', 'queue.default' => 'sync']);
