<?php

declare(strict_types=1);

/** #2879: rzeczywisty zapis postępu i publiczna akcja zawieszenia na osobnych procesach. */

use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Models\CookingSession;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array<string, string> $argumenty */
$argumenty = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$etap = $argv[1] ?? '';
$dowod = [];

/** @param array<string, mixed> $wynik */
function melduj2879(array $wynik): never
{
    echo json_encode(array_merge([
        'ok' => false, 'wartosc' => null, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null,
    ], $wynik), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(0);
}

try {
    $baza = DB::connection()->getDatabaseName();
    if (! str_starts_with($baza, 'kuking_race') || $baza !== kuking_nazwa_bazy_wyscigow(dirname(__DIR__, 3))
        || ($baza === 'kuking_race' && getenv('CI') !== 'true')) {
        throw new RuntimeException('Odmowa: wymagane własne kuking_race_* wyliczone z tego worktree.');
    }
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '30s'");
    DB::statement("SET idle_in_transaction_session_timeout = '30s'");
    DB::select("SELECT set_config('application_name', ?, false)", ['postep2879-'.$etap]);

    $konto = User::query()->findOrFail($argumenty['konto']);
    $dowod = ['status_wejsciowy' => $konto->status, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];

    if ($etap === 'zawies') {
        $dowod['zamki_konta'] = [];
        DB::listen(static function (QueryExecuted $query) use (&$dowod): void {
            if (str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for update')) {
                $dowod['zamki_konta'][] = $query->sql;
            }
        });
        // Nie zastępujemy zawieszenia gołym UPDATE ani ręcznym zamkiem.
        $konto->suspend();
        $dowod['status_po'] = $konto->fresh()?->status;
    } elseif ($etap === 'postep') {
        $sesja = CookingSession::query()->findOrFail($argumenty['sesja']);
        $zatrzymany = false;
        $czyZamekKonta = static fn (string $sql): bool => str_contains($sql, 'from "users"')
            && (str_contains($sql, 'for key share') || str_contains($sql, 'for share'));

        if ($argumenty['bariera'] === 'przed') {
            // Pierwszy zamek akcji jest dopiero po jej rzeczywistej Policy.
            // Zatrzymanie poprzedza odczyt pod zamkiem, nie odgrywa jego wyniku.
            DB::connection()->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use (&$zatrzymany, $czyZamekKonta, &$dowod): void {
                if (! $zatrzymany && $czyZamekKonta($sql)) {
                    $zatrzymany = true;
                    $dowod['transakcja'] = $connection->transactionLevel();
                    $dowod['sql_konta'] = $sql;
                    DB::select('SELECT pg_advisory_xact_lock(2879, 1)');
                }
            });
        } elseif ($argumenty['bariera'] === 'po') {
            // QueryExecuted oznacza, że rzeczywisty zamek już jest trzymany.
            DB::listen(static function (QueryExecuted $query) use (&$zatrzymany, $czyZamekKonta, &$dowod): void {
                if (! $zatrzymany && $czyZamekKonta($query->sql)) {
                    $zatrzymany = true;
                    $dowod['transakcja'] = $query->connection->transactionLevel();
                    $dowod['sql_konta'] = $query->sql;
                    DB::select('SELECT pg_advisory_xact_lock(2879, 1)');
                }
            });
        } else {
            throw new RuntimeException('Nieznana bariera #2879.');
        }

        $akcja = app(PostepWspolnegoGotowania::class);
        if ($argumenty['operacja'] === 'wyczysc') {
            $akcja->wyczysc($konto, $sesja);
            $dowod['zmieniono'] = true;
        } else {
            $dowod['zmieniono'] = $akcja->ustaw($konto, $sesja, $argumenty['krok'], $argumenty['operacja'] === 'zrobiono');
        }
        $dowod['status_starego_modelu'] = $konto->status;
    } else {
        throw new RuntimeException('Nieznany etap #2879.');
    }

    melduj2879(['ok' => true, 'wartosc' => $dowod]);
} catch (Throwable $e) {
    $sqlstate = $e instanceof PDOException ? (string) $e->getCode() : null;
    if ($e instanceof QueryException) {
        $sqlstate = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : $sqlstate;
    }
    melduj2879(['wartosc' => $dowod, 'wyjatek' => $e::class, 'sqlstate' => $sqlstate, 'komunikat' => $e->getMessage()]);
}
