<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2650: udostępnienie przepisu po nazwie konta kontra blokada.
 *
 * Woła PRAWDZIWE akcje. Bariera należy wyłącznie do przyrządu: zatrzymuje
 * udostępnienie PO blokadzie doradczej na przepis (`after_sql`), czyli po
 * sprawdzeniu blokady pod zamkiem pary, a przed wstawieniem wiersza.
 */

use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '8s'");
DB::statement("SET idle_in_transaction_session_timeout = '12s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $armed = isset($args['after_sql']);
    DB::listen(function (QueryExecuted $query) use (&$armed, $args): void {
        if (! $armed) {
            return;
        }
        foreach ((array) $args['after_sql'] as $fragment) {
            if (! str_contains(strtolower($query->sql), $fragment)) {
                return;
            }
        }
        $armed = false;
        DB::selectOne('SELECT pg_advisory_xact_lock(9158, hashtext(?))', [$args['name']]);
    });

    $result = match ($argv[1]) {
        'udostepnij' => (function () use ($args): string {
            [$udostepnienie] = app(UdostepnijPrzepis::class)->poNazwie(
                User::query()->findOrFail($args['actor']),
                Recipe::query()->findOrFail($args['przepis']),
                (string) $args['nazwa'],
            );

            return (string) $udostepnienie->getKey();
        })(),
        'block' => (function () use ($args): bool {
            app(BlockUser::class)->handle(User::query()->findOrFail($args['actor']), User::query()->findOrFail($args['other']));

            return true;
        })(),
        default => throw new RuntimeException('Nieznany scenariusz.'),
    };
    echo json_encode(['ok' => true, 'wartosc' => $result, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
