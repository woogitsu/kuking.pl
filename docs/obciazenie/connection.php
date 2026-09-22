<?php

declare(strict_types=1);

/** Parametry tylko izolowanej, jawnie wskazanej bazy. Bez połączenia w walidacji. */
function benchmarkConnection(): array
{
    $db = getenv('BENCH_DB') ?: '';
    $host = getenv('PGHOST') ?: '';
    $port = getenv('PGPORT') ?: '';
    $user = getenv('PGUSER') ?: '';
    if (! preg_match('/\Akuking_bench_[a-z0-9_]{1,40}\z/', $db)
        || ! in_array($host, ['127.0.0.1', 'localhost'], true)
        || ! preg_match('/\A[0-9]{4,5}\z/', $port)
        || (int) $port < 1024 || (int) $port > 65535 || (int) $port === 5432
        || ! preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $user)
        || strlen((string) getenv('PGHOSTADDR')) > 0
        || strlen((string) getenv('PGSERVICE')) > 0
        || strlen((string) getenv('PGSERVICEFILE')) > 0) {
        throw new InvalidArgumentException('Wskaż BENCH_DB kuking_bench_*, lokalny PGHOST, izolowany PGPORT (nie 5432) i PGUSER.');
    }

    return ["pgsql:host={$host};port={$port};dbname={$db}", $user, getenv('PGPASSWORD') ?: null];
}
