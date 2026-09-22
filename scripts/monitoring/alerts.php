<?php

declare(strict_types=1);

// Wczytywany wyłącznie po strażniku lokalnej bazy z local.php.
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

config(['logging.channels.blad_webhook.url' => 'http://127.0.0.1:8599/alarm']);
Log::forgetChannel('blad_webhook');
Http::allowStrayRequests(['http://127.0.0.1:8599/*']);
Cache::flush();
DB::table('jobs')->delete();
DB::table('failed_jobs')->delete();
$results = [];
$call = function (string $name, string $command, int $expected) use (&$results): void {
    $code = Artisan::call($command);
    if ($code !== $expected) {
        throw new RuntimeException('Nieoczekiwany wynik próby '.$name);
    }
    $results[] = ['case' => $name, 'exit' => $code];
};
$call('kanał próbny', 'kuking:sprawdz-alarm', 0);
config(['kuking.polaczenia.prog_ostrzegawczy' => 100000, 'kuking.polaczenia.prog_krytyczny' => 100000]);
$call('połączenia spokojne', 'kuking:budzet-polaczen', 0);
config(['kuking.polaczenia.prog_ostrzegawczy' => 1]);
$call('połączenia ponad progiem ostrzegawczym', 'kuking:budzet-polaczen', 1);
$call('powtórzenie ostrzeżenia', 'kuking:budzet-polaczen', 1);
config(['kuking.polaczenia.prog_krytyczny' => 1]);
$call('eskalacja połączeń', 'kuking:budzet-polaczen', 1);
config(['kuking.polaczenia.prog_ostrzegawczy' => 100000, 'kuking.polaczenia.prog_krytyczny' => 100000]);
$call('odwołanie połączeń', 'kuking:budzet-polaczen', 0);
$call('kolejka spokojna', 'kuking:sprawdz-kolejke', 0);
$id = DB::table('jobs')->insertGetId([
    'queue' => 'media', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
    'available_at' => time() - 900, 'created_at' => time() - 900,
]);
$call('zaległość media 900 sekund', 'kuking:sprawdz-kolejke', 1);
$call('powtórzenie zaległości', 'kuking:sprawdz-kolejke', 1);
DB::table('jobs')->where('id', $id)->delete();
$call('odwołanie kolejki', 'kuking:sprawdz-kolejke', 0);
DB::table('failed_jobs')->insert([
    'uuid' => (string) Str::uuid(), 'connection' => 'database',
    'queue' => 'media', 'payload' => '{}', 'exception' => 'lokalna kontrola', 'failed_at' => now(),
]);
$call('świeże nieudane zadanie', 'kuking:sprawdz-kolejke', 1);
DB::table('failed_jobs')->delete();
$call('odwołanie nieudanego zadania', 'kuking:sprawdz-kolejke', 0);
$id = DB::table('jobs')->insertGetId([
    'queue' => 'media', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time() - 4000,
    'available_at' => time(), 'created_at' => time() - 4000,
]);
$call('zawieszona rezerwacja', 'kuking:sprawdz-kolejke', 1);
DB::table('jobs')->where('id', $id)->delete();
$call('odwołanie rezerwacji', 'kuking:sprawdz-kolejke', 0);
// Awaria połączenia przez nieistniejącą bazę na tym samym dozwolonym porcie.
// Nie zatrzymujemy serwera ani nie naruszamy innych baz stanowiska.
config(['database.connections.pgsql.database' => 'kuking_flota_gpt-monitoring_missing']);
DB::purge('pgsql');
$call('baza niedostępna i cache niedostępny', 'kuking:budzet-polaczen', 1);
$call('kolejka niedostępna i cache niedostępny', 'kuking:sprawdz-kolejke', 1);
file_put_contents(storage_path('monitoring-alert-cases.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Próby: '.count($results).PHP_EOL;
