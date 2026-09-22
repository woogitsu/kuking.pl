<?php

declare(strict_types=1);
require dirname(__DIR__, 4).'/vendor/autoload.php';
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Database\PostgresConnection;
use Illuminate\Queue\DatabaseQueue;

$dsn = 'pgsql:host=127.0.0.1;port=55439;dbname=kuking_flota_gpt-widmo-zamkniec';
$a = new PDO($dsn, 'kuking', 'kuking', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$b = new PDO($dsn, 'kuking', 'kuking', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$table = 'probe606_'.bin2hex(random_bytes(5));
try {
    $a->exec('CREATE TABLE '.$table.' (id bigserial PRIMARY KEY, queue text NOT NULL, payload text NOT NULL, attempts smallint NOT NULL, reserved_at integer, available_at integer NOT NULL, created_at integer NOT NULL)');
    $connection = new PostgresConnection($b, 'kuking_flota_gpt-widmo-zamkniec', '', ['driver' => 'pgsql']);
    $connection->enableQueryLog();
    $queue = new DatabaseQueue($connection, $table, 'default', 960);
    $queue->setContainer(new Container);
    $payload = json_encode(['uuid' => 'probe606', 'displayName' => 'probe606', 'job' => 'probe606', 'data' => []]);
    $first = $queue->pushRaw($payload);
    $second = $queue->pushRaw($payload);
    $a->beginTransaction();
    $a->query('SELECT id FROM '.$table.' WHERE id = '.(int) $first.' FOR UPDATE')->fetch();
    $b->exec("SET lock_timeout = '500ms'");
    $job = $queue->pop();
    if ((int) $job->getJobId() !== (int) $second) {
        throw new RuntimeException('Nie ominieto zablokowanego zadania');
    }
    $queries = array_column($connection->getQueryLog(), 'query');
    $locks = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'SKIP LOCKED')));
    if (count($locks) !== 1) {
        throw new RuntimeException('Brak oczekiwanego SQL');
    }
    $negative = false;
    try {
        $b->query('SELECT id FROM '.$table.' ORDER BY id FOR UPDATE')->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() !== '55P03') {
            throw $e;
        } $negative = true;
    }
    if (! $negative) {
        throw new RuntimeException('Kontrola ujemna nie wykryla blokady');
    }
    echo json_encode(['postgres' => $a->getAttribute(PDO::ATTR_SERVER_VERSION), 'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'), 'reference' => InstalledVersions::getReference('laravel/framework'), 'first_locked' => $first, 'popped' => $job->getJobId(), 'sql' => $locks, 'without_skip_locked' => '55P03 lock timeout'], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($a->inTransaction()) {
        $a->rollBack();
    }
    $a->exec('DROP TABLE IF EXISTS '.$table);
    echo 'CLEANUP: '.($a->query("SELECT to_regclass('$table') IS NULL")->fetchColumn() ? 'PASS' : 'FAIL').PHP_EOL;
}
