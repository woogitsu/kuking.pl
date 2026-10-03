<?php

declare(strict_types=1);

use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', (string) DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '30s'");
DB::statement("SET idle_in_transaction_session_timeout = '30s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $osoba = User::query()->findOrFail($args['kto']);
    $zaproszenie = CollectionInvitation::query()->findOrFail($args['zaproszenie']);

    // Bariera następuje po PRAWDZIWYM SELECT ... FOR UPDATE w akcji.
    // Stara odmowa trzyma wtedy zaproszenie, nowa także oba konta.
    $zatrzymany = false;
    DB::listen(static function (QueryExecuted $query) use ($args, &$zatrzymany): void {
        $sql = strtolower($query->sql);
        if ($zatrzymany || ! str_contains($sql, 'from "collection_invitations"')
            || ! str_contains($sql, 'for update')) {
            return;
        }

        $zatrzymany = true;
        DB::selectOne('SELECT pg_advisory_xact_lock(92838, hashtext(?))', [$args['name']]);
    });

    $akcja = app(OdpowiedzNaZaproszenie::class);
    $wartosc = $argv[1] === 'przyjmij'
        ? (string) $akcja->przyjmij($osoba, $zaproszenie)->getKey()
        : (function () use ($akcja, $osoba, $zaproszenie): string {
            $akcja->odrzuc($osoba, $zaproszenie);

            return 'odrzucono';
        })();

    echo json_encode(['ok' => true, 'wartosc' => $wartosc, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'komunikat' => $exception->getMessage(), 'wyjatek' => $exception::class,
    ]);
}
