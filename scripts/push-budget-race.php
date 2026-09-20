<?php

declare(strict_types=1);

// Lokalny pomiar dwóch prawdziwych procesów. Bez migracji i czyszczenia bazy.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domain\Notifications\PushDailyBudget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$db = config('database.connections.pgsql');
if (! app()->environment(['local', 'testing']) || $db['host'] !== '127.0.0.1'
    || (string) $db['port'] !== '55439' || ! str_starts_with($db['database'], 'kuking_flota_')) {
    throw new RuntimeException('Użyj własnej lokalnej bazy kuking_flota_* na 127.0.0.1:55439.');
}
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '20s'");
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC'));

if (($argv[1] ?? '') === 'worker') {
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    echo json_encode(['pid' => $pid])."\n";
    flush();
    $reserved = app(PushDailyBudget::class)->reserve(User::findOrFail($argv[2]), 'Europe/Warsaw');
    echo json_encode(['reserved' => $reserved])."\n";
    exit;
}

$user = User::factory()->create(['wants_weekly_digest' => false]);
$processes = [];
try {
    DB::beginTransaction();
    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    $parentPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    for ($i = 0; $i < 2; $i++) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $user->id], dirname(__DIR__));
        $process->setTimeout(25);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 10;
    do {
        $pids = [];
        foreach ($processes as $process) {
            $line = explode("\n", $process->getOutput())[0];
            $pid = json_decode($line, true)['pid'] ?? null;
            if ($pid !== null) {
                $pids[] = $pid;
            }
        }
        $blocked = count($pids) === 2
            ? DB::selectOne('SELECT count(*) AS n FROM pg_stat_activity WHERE pid IN (?, ?) AND cardinality(pg_blocking_pids(pid)) > 0', $pids)->n
            : 0;
        if ((int) $blocked === 2) {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);

    if ((int) $blocked !== 2 || count(array_unique([...$pids, $parentPid])) !== 3) {
        throw new RuntimeException('Nie zmierzono dwóch niezależnych uczestników czekających na blokadę.');
    }
    DB::commit();
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Proces rezerwacji nie zakończył się poprawnie: '.$process->getErrorOutput());
        }
        $lines = explode("\n", trim($process->getOutput()));
        $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['reserved'];
    }
    sort($results);
    $rows = DB::table('push_daily_reservations')->where('user_id', $user->id)->count();
    if ($results !== [false, true] || $rows !== 1) {
        throw new RuntimeException('Dwie równoczesne próby nie dały dokładnie jednej rezerwacji.');
    }
    echo 'PASS: trzy różne połączenia, dwa oczekiwania, wyniki [false,true], jeden wiersz.'.PHP_EOL;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($processes as $process) {
        $process->stop();
    }
    $user->delete();
    if (DB::table('push_daily_reservations')->where('user_id', $user->id)->exists()) {
        throw new RuntimeException('Po pomiarze pozostała rezerwacja.');
    }
}
