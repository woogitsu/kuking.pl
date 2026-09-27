<?php

declare(strict_types=1);

/**
 * Pomiar #1309: koszt raportów analitycznych przy dużej historii zamkniętych kont.
 *
 * Uruchamia `kuking:wac`, `kuking:raport` i `kuking:policz-kukingow` na wskazanej
 * wersji kodu, zbiera każde zapytanie (SQL, liczba parametrów, czas) i dla każdego
 * SELECT-a wykonuje `EXPLAIN (ANALYZE, BUFFERS)`. Ten sam skrypt mierzy obie wersje,
 * bo woła wyłącznie komendy — nie zna klas, które #1593 zmienia.
 *
 * Dane: `scripts/pomiar-1309-dane.sql` na pustej, zmigrowanej bazie lokalnej.
 *
 * Użycie (katalog = korzeń mierzonej wersji, z własnym `vendor/`):
 *   DB_DATABASE=kuking_pomiar_1309 php scripts/pomiar-1309.php <katalog> <plik-planów.txt> > wynik.json
 *
 * Wynik: JSON na STDOUT, pełne plany do pliku, postęp na STDERR.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$korzen = realpath($argv[1] ?? '') ?: throw new InvalidArgumentException('Podaj katalog mierzonej wersji.');
$plikPlanow = $argv[2] ?? throw new InvalidArgumentException('Podaj plik na plany.');

require $korzen.'/vendor/autoload.php';
$app = require $korzen.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

// Bezpiecznik: wyłącznie lokalna baza pomiarowa.
$polaczenie = DB::connection();
if ($app->environment('production')
    || $polaczenie->getDriverName() !== 'pgsql'
    || ! in_array($polaczenie->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || ! str_starts_with((string) $polaczenie->getDatabaseName(), 'kuking_pomiar_1309')) {
    throw new RuntimeException('Pomiar wymaga lokalnej bazy kuking_pomiar_1309*.');
}

$biezace = null;
$zapytania = [];
DB::listen(function (QueryExecuted $q) use (&$biezace, &$zapytania): void {
    if ($biezace !== null) {
        $zapytania[$biezace][] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
    }
});

$wynik = ['wersja' => trim((string) shell_exec('git -C '.escapeshellarg($korzen).' rev-parse --short HEAD')), 'komendy' => []];
$plany = '';

foreach (['kuking:wac', 'kuking:raport', 'kuking:policz-kukingow'] as $komenda) {
    // Rozgrzewka: pierwsze wywołanie płaci za bufory i kompilację klas.
    // Wyjątek to też wynik pomiaru (np. przekroczony limit parametrów
    // protokołu PostgreSQL) — zapisujemy go i mierzymy dalej.
    try {
        Artisan::call($komenda);
    } catch (Throwable $blad) {
        $wynik['komendy'][$komenda] = ['blad' => substr($blad->getMessage(), 0, 160)];
        fwrite(STDERR, "{$komenda}: BŁĄD ".substr($blad->getMessage(), 0, 160).PHP_EOL);

        continue;
    }

    gc_collect_cycles();
    $pamiecStart = memory_get_usage();
    memory_reset_peak_usage();
    $biezace = $komenda;
    $start = hrtime(true);
    Artisan::call($komenda);
    $ms = (hrtime(true) - $start) / 1e6;
    $biezace = null;
    $szczyt = memory_get_peak_usage() - $pamiecStart;
    // Wynik komendy — obie wersje mają dać TE SAME liczby (kryterium #1309).
    $wyjscie = trim(Artisan::output());

    $lista = $zapytania[$komenda] ?? [];
    $wynik['komendy'][$komenda] = [
        'czas_ms' => round($ms, 1),
        'szczyt_pamieci_php_kb' => round($szczyt / 1024),
        'zapytan' => count($lista),
        'parametrow_razem' => array_sum(array_map(fn ($z) => count($z['bindings']), $lista)),
        'najwiecej_parametrow' => max(array_map(fn ($z) => count($z['bindings']), $lista) ?: [0]),
        'najdluzszy_sql_znakow' => max(array_map(fn ($z) => strlen($z['sql']), $lista) ?: [0]),
        'czas_sql_ms' => round(array_sum(array_column($lista, 'ms')), 1),
        'wyjscie_sha1' => sha1($wyjscie),
        'wyjscie' => $wyjscie,
        'explain' => [],
    ];

    foreach ($lista as $i => $z) {
        if (! preg_match('/^\s*(select|with|\()/i', $z['sql'])) {
            continue;
        }
        $wiersze = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$z['sql'], $z['bindings']);
        $tekst = implode("\n", array_map(fn ($w) => $w->{'QUERY PLAN'}, $wiersze));
        preg_match('/Execution Time: ([\d.]+) ms/', $tekst, $czas);
        preg_match('/Planning Time: ([\d.]+) ms/', $tekst, $plan);
        preg_match_all('/shared hit=(\d+)(?: read=(\d+))?/', $tekst, $bufory);
        $wynik['komendy'][$komenda]['explain'][] = [
            'nr' => $i,
            'parametrow' => count($z['bindings']),
            'planowanie_ms' => (float) ($plan[1] ?? 0),
            'wykonanie_ms' => (float) ($czas[1] ?? 0),
            // Pierwsza linia z buforami to węzeł główny — suma całego planu.
            'bufory_hit' => (int) ($bufory[1][0] ?? 0),
            'bufory_read' => (int) ($bufory[2][0] ?? 0),
        ];
        $sql = strlen($z['sql']) > 600 ? substr($z['sql'], 0, 600).' … ['.strlen($z['sql']).' znaków]' : $z['sql'];
        $plany .= "=== {$komenda} #{$i} (parametrów: ".count($z['bindings']).")\n{$sql}\n\n{$tekst}\n\n";
    }

    fwrite(STDERR, sprintf("%s: %.0f ms, %d zapytań, %d parametrów\n", $komenda, $ms, count($lista), $wynik['komendy'][$komenda]['parametrow_razem']));
}

file_put_contents($plikPlanow, $plany);
echo json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
