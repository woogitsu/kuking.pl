<?php

declare(strict_types=1);

use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
    $wlasciciel = User::query()->findOrFail($args['actor']);
    if ($argv[1] === 'suspend') {
        $wlasciciel->suspend();
        $wartosc = true;
    } else {
        $zeszyt = Collection::query()->findOrFail($args['zeszyt']);
        if ($args['moment'] === 'przed_zamkiem') {
            // Zwykła początkowa autoryzacja na starym modelu, następnie
            // rzeczywista bariera między nią a akcją. Akcja dostaje TEN model.
            Gate::forUser($wlasciciel)->authorize('share', $zeszyt);
            DB::beginTransaction();
            DB::selectOne('SELECT pg_advisory_xact_lock(92835, hashtext(?))', [$args['name']]);
        } else {
            $zatrzymaj = true;
            DB::listen(function (QueryExecuted $query) use (&$zatrzymaj, $args): void {
                if (! $zatrzymaj || ! str_contains(strtolower($query->sql), 'from "users"')
                    || ! str_contains(strtolower($query->sql), 'for update')
                    || ! in_array($args['actor'], $query->bindings, true)) {
                    return;
                }
                $zatrzymaj = false;
                DB::selectOne('SELECT pg_advisory_xact_lock(92835, hashtext(?))', [$args['name']]);
            });
        }

        $akcja = app(ZaprosDoZeszytu::class);
        $wartosc = $argv[1] === 'nazwa'
            ? (string) $akcja->poNazwie($wlasciciel, $zeszyt, (string) $args['nazwa'])->getKey()
            : (string) $akcja->linkiem($wlasciciel, $zeszyt)[0]->getKey();
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }
    echo json_encode(['ok' => true, 'wartosc' => $wartosc, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
