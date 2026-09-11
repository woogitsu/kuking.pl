<?php

declare(strict_types=1);

/**
 * Przygotowuje bazę wyścigów dla grupy `dwa-polaczenia` (D-105): zakłada ją,
 * jeśli nie istnieje, i doprowadza schemat do stanu z migracji.
 *
 * DLACZEGO PHP, A NIE `createdb`. Bo `createdb` to osobny program, którego na
 * runnerze CI może nie być (obraz bez `postgresql-client`), a jeśli jest, to
 * łączy się po swojemu — peer authentication lokalnie, zmienne `PG*` w CI.
 * Wychodziły z tego dwa różne sposoby łączenia się z tą samą bazą w jednym
 * repozytorium. Tu jest jeden: konfiguracja Laravela, ta sama, której użyją
 * testy. Jeśli skrypt się połączy, połączą się i one.
 *
 * ODMAWIA, gdy nazwa bazy nie zaczyna się od `kuking_race`. Ten plik URUCHAMIA
 * MIGRACJE, czyli potrafi ruszyć schemat — puszczony przez pomyłkę na
 * `kuking_test_*` albo na `kuking` zrobiłby dokładnie to, przed czym broni
 * issue #66.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

$nazwa = kuking_nazwa_bazy_wyscigow(__DIR__.'/../../..');

putenv('DB_DATABASE='.$nazwa);
$_ENV['DB_DATABASE'] = $nazwa;
$_SERVER['DB_DATABASE'] = $nazwa;

$app->make(Kernel::class)->bootstrap();

if (! str_starts_with($nazwa, 'kuking_race')) {
    fwrite(STDERR, 'Odmowa: baza wyścigów wyszła jako "'.$nazwa."\", a musi zaczynać się od kuking_race.\n");
    exit(1);
}

/** @var array{host: string, port: string|int, username: string, password: string} $config */
$config = config('database.connections.pgsql');

// Połączenie do bazy `postgres`, bo do bazy, której jeszcze nie ma, nie da
// się połączyć. `postgres` istnieje w każdej instalacji PostgreSQL.
$serwer = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$istnieje = $serwer->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$istnieje->execute([$nazwa]);

if ($istnieje->fetchColumn() === false) {
    // Nazwa jest sprawdzona wzorcem wyżej (`kuking_race` + [a-zA-Z0-9_]
    // z `tests/bootstrap.php`), a `CREATE DATABASE` nie przyjmuje parametrów
    // wiązanych — dlatego jest tu sklejana, i dlatego tamten wzorzec jest
    // wąski.
    $serwer->exec('CREATE DATABASE "'.$nazwa.'"');
    fwrite(STDOUT, 'Założona baza wyścigów: '.$nazwa."\n");
}

DB::purge();

$kod = $app->make(Kernel::class)->call('migrate', ['--force' => true, '--no-interaction' => true]);

if ($kod !== 0) {
    fwrite(STDERR, 'Migracje na bazie '.$nazwa." nie przeszły.\n");
    exit(1);
}

fwrite(STDOUT, 'Baza wyścigów gotowa: '.$nazwa."\n");
exit(0);
