<?php

declare(strict_types=1);

/** Osobny proces sprawdzający kolejność dwóch blokad przyrządu #2598. */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Przyrząd odmawia uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '8s'");
DB::statement("SET idle_in_transaction_session_timeout = '12s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    if ($argv[1] === 'hold') {
        DB::selectOne('SELECT pg_advisory_lock(9158, hashtext(?))', [$args['key']]);
        try {
            // Rodzic trzyma tę bramkę aż zobaczy, że próba stoi na obcej blokadzie.
            DB::selectOne('SELECT pg_advisory_lock(9159, hashtext(?))', [$args['key']]);
            DB::selectOne('SELECT pg_advisory_unlock(9159, hashtext(?))', [$args['key']]);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(9158, hashtext(?))', [$args['key']]);
        }
    } elseif ($argv[1] === 'probe') {
        DB::selectOne('SELECT pg_advisory_lock(9158, hashtext(?))', [$args['key']]);
        DB::selectOne('SELECT pg_advisory_unlock(9158, hashtext(?))', [$args['key']]);
        DB::selectOne('SELECT pg_advisory_lock(9160, hashtext(?))', [$args['key']]);
        DB::selectOne('SELECT pg_advisory_unlock(9160, hashtext(?))', [$args['key']]);
    } else {
        throw new LogicException('Nieznany uczestnik przyrządu.');
    }

    echo json_encode(['ok' => true, 'wartosc' => true, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
