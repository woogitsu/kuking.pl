<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2073: ponowienie eksportu (`queue:retry`) kontra nocne
 * `kuking:sprzataj-eksporty` na tym samym rekordzie `failed`.
 *
 * Scenariusz `ponowienie` uruchamia PRAWDZIWE `GenerateUserExport::handle()`
 * dla wskazanego eksportu — tak jak worker po `queue:retry` — na dysku
 * lokalnym, którego katalog współdzieli z procesem testu. Dzięki temu test
 * widzi dokładnie ten plik, który zapisał worker, i może sprawdzić, czy
 * sprzątanie go nie zabrało.
 *
 * Melduje jednym wierszem JSON-a (patrz `tests/Dwa/bin/scenariusz.php`).
 */

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

// Ta sama reguła rodziny baz co w `komentarz.php`.
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}

DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '20s'");
DB::statement("SET idle_in_transaction_session_timeout = '20s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    config([
        "filesystems.disks.{$args['disk']}" => ['driver' => 'local', 'root' => $args['root']],
        'kuking.exports.disk' => $args['disk'],
        'kuking.exports.ttl_days' => 7,
    ]);

    $result = match ($argv[1]) {
        'ponowienie' => (function () use ($args): ?string {
            (new GenerateUserExport($args['export']))->handle();

            return DataExport::query()->whereKey($args['export'])->value('status');
        })(),
        default => throw new RuntimeException('Nieznany scenariusz.'),
    };

    echo json_encode(['ok' => true, 'wartosc' => $result, 'sqlstate' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
