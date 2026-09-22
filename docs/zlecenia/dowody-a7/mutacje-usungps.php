<?php

declare(strict_types=1);

/**
 * A7-1 — mutation testing `UsunGps.php` bez zależności od generatora coverage.
 * Każdy mutant naprawdę podmienia kod źródłowy, uruchamia testy i w `finally`
 * przywraca plik. Mutanty, które przeżyją testy związane z UsunGps/GPS,
 * są dodatkowo potwierdzane pełnym `php artisan test --compact`.
 *
 * Uruchomienie WYŁĄCZNIE na osobnej bazie PostgreSQL:
 *   DB_DATABASE=kuking_test_a7_mutacje php docs/zlecenia/dowody-a7/mutacje-usungps.php
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ((string) DB::connection()->getDatabaseName() !== 'kuking_test_a7_mutacje') {
    fwrite(STDERR, "ODMOWA: oczekiwano DB_DATABASE=kuking_test_a7_mutacje.\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$sourcePath = $root.'/app/Domain/Media/UsunGps.php';
$original = (string) file_get_contents($sourcePath);
if ($original === '') {
    throw new RuntimeException('Nie udało się odczytać UsunGps.php.');
}

/** @return array{exit_code:int,wall_ms:float,output:string} */
function a7Run(array $args, string $cwd): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
    $start = hrtime(true);
    exec('cd '.escapeshellarg($cwd).' && '.$cmd, $lines, $code);

    return [
        'exit_code' => $code,
        'wall_ms' => round((hrtime(true) - $start) / 1_000_000, 3),
        'output' => implode("\n", $lines),
    ];
}

/** @return list<string> */
function a7TestsForUsunGps(string $root): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests'));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $name = $file->getFilename();
        $content = (string) file_get_contents($path);
        if (stripos($name, 'gps') !== false || stripos($name, 'wspolrzed') !== false || str_contains($content, 'UsunGps')) {
            $out[] = substr($path, strlen($root) + 1);
        }
    }
    sort($out);

    return array_values(array_unique($out));
}

$tests = a7TestsForUsunGps($root);
if ($tests === []) {
    throw new RuntimeException('Nie znaleziono żadnych testów dotyczących UsunGps/GPS.');
}

$mutants = [
    [
        'id' => 'M01_png_detector_off',
        'description' => 'wyłącza rozpoznanie PNG',
        'from' => 'if (str_starts_with($bajty, self::SYGNATURA_PNG)) {',
        'to' => 'if (false) {',
    ],
    [
        'id' => 'M02_webp_detector_off',
        'description' => 'wyłącza rozpoznanie WebP',
        'from' => "if (str_starts_with(\$bajty, 'RIFF') && substr(\$bajty, 8, 4) === 'WEBP') {",
        'to' => 'if (false) {',
    ],
    [
        'id' => 'M03_jpeg_detector_off',
        'description' => 'wyłącza rozpoznanie JPEG',
        'from' => 'if (str_starts_with($bajty, "\\xFF\\xD8")) {',
        'to' => 'if (false) {',
    ],
    [
        'id' => 'M04_png_chunk_name',
        'description' => 'przestaje rozpoznawać chunk eXIf',
        'from' => "if (\$typ === 'eXIf') {",
        'to' => "if (\$typ === 'eXIz') {",
    ],
    [
        'id' => 'M05_webp_chunk_name',
        'description' => 'przestaje rozpoznawać chunk EXIF',
        'from' => "if (\$typ === 'EXIF') {",
        'to' => "if (\$typ === 'EXIz') {",
    ],
    [
        'id' => 'M06_webp_odd_padding',
        'description' => 'ignoruje dopełnienie nieparzystego chunku WebP',
        'from' => '$nastepny = $poz + 8 + $dlugosc + ($dlugosc % 2);',
        'to' => '$nastepny = $poz + 8 + $dlugosc;',
    ],
    [
        'id' => 'M07_jpeg_sos_marker',
        'description' => 'nie zatrzymuje parsera na SOS',
        'from' => 'if ($znacznik === 0xDA) {',
        'to' => 'if ($znacznik === 0xDB) {',
    ],
    [
        'id' => 'M08_jpeg_app1_marker',
        'description' => 'szuka EXIF w APP2 zamiast APP1',
        'from' => 'if ($znacznik === 0xE1 && substr($bajty, $poz + 4, strlen(self::NAGLOWEK)) === self::NAGLOWEK) {',
        'to' => 'if ($znacznik === 0xE2 && substr($bajty, $poz + 4, strlen(self::NAGLOWEK)) === self::NAGLOWEK) {',
    ],
    [
        'id' => 'M09_little_endian_inverted',
        'description' => 'traktuje II jak big-endian',
        'from' => "'II' => true,",
        'to' => "'II' => false,",
    ],
    [
        'id' => 'M10_big_endian_inverted',
        'description' => 'traktuje MM jak little-endian',
        'from' => "'MM' => false,",
        'to' => "'MM' => true,",
    ],
    [
        'id' => 'M11_tiff_magic_42',
        'description' => 'oczekuje magicznej liczby 43 zamiast 42',
        'from' => 'if (self::short($bajty, $tiff + 2, $maloEndian) !== 42) {',
        'to' => 'if (self::short($bajty, $tiff + 2, $maloEndian) !== 43) {',
    ],
    [
        'id' => 'M12_gps_tag',
        'description' => 'zmienia tag GPS IFD z 0x8825 na 0x8824',
        'from' => 'private const TAG_GPS_IFD = 0x8825;',
        'to' => 'private const TAG_GPS_IFD = 0x8824;',
    ],
    [
        'id' => 'M13_inline_boundary',
        'description' => 'wartość dokładnie 4 B traktuje jak offset',
        'from' => 'if ($dlugosc <= 4) {',
        'to' => 'if ($dlugosc < 4) {',
    ],
    [
        'id' => 'M14_png_crc_bypass',
        'description' => 'nie przelicza CRC PNG po sanitacji',
        'from' => 'return $chunkPng === null',
        'to' => 'return true',
    ],
    [
        'id' => 'M15_prefixed_png_webp_not_skipped',
        'description' => 'nie pomija prefiksu Exif\\0\\0 w chunku PNG/WebP',
        'from' => "return substr(\$bajty, \$od, strlen(self::NAGLOWEK)) === self::NAGLOWEK\n            ? \$od + strlen(self::NAGLOWEK)\n            : \$od;",
        'to' => 'return $od;',
    ],
    [
        'id' => 'M16_png_crc_valid_boundary',
        'description' => 'odmawia CRC, gdy chunk kończy się dokładnie na końcu pliku',
        'from' => 'if ($koniecDanych + 4 > strlen($bajty)) {',
        'to' => 'if ($koniecDanych + 4 >= strlen($bajty)) {',
    ],
];

$baseline = a7Run(array_merge(['php', 'artisan', 'test', '--compact'], $tests), $root);
if ($baseline['exit_code'] !== 0) {
    throw new RuntimeException("Bazowe testy UsunGps/GPS nie są zielone:\n".$baseline['output']);
}

$results = [];
try {
    foreach ($mutants as $m) {
        $count = substr_count($original, $m['from']);
        if ($count !== 1) {
            $results[] = [
                'id' => $m['id'],
                'description' => $m['description'],
                'status' => 'INVALID',
                'reason' => "wzorzec występuje {$count} razy zamiast dokładnie raz",
            ];

            continue;
        }

        $mutated = str_replace($m['from'], $m['to'], $original, $replaced);
        if ($replaced !== 1 || $mutated === $original) {
            $results[] = [
                'id' => $m['id'],
                'description' => $m['description'],
                'status' => 'INVALID',
                'reason' => 'podmiana nie zmieniła dokładnie jednego miejsca',
            ];

            continue;
        }

        file_put_contents($sourcePath, $mutated);
        clearstatcache(true, $sourcePath);
        $targeted = a7Run(array_merge(['php', 'artisan', 'test', '--compact'], $tests), $root);
        file_put_contents($sourcePath, $original);
        clearstatcache(true, $sourcePath);

        if ($targeted['exit_code'] !== 0) {
            $results[] = [
                'id' => $m['id'],
                'description' => $m['description'],
                'status' => 'KILLED',
                'targeted_wall_ms' => $targeted['wall_ms'],
                'targeted_output_tail' => substr($targeted['output'], -4000),
            ];

            continue;
        }

        // Survivor jest potwierdzany całym zestawem, żeby nie pomylić
        // braku literalnej referencji do UsunGps z brakiem ochrony integracyjnej.
        file_put_contents($sourcePath, $mutated);
        clearstatcache(true, $sourcePath);
        $full = a7Run(['php', 'artisan', 'test', '--compact'], $root);
        file_put_contents($sourcePath, $original);
        clearstatcache(true, $sourcePath);

        $results[] = [
            'id' => $m['id'],
            'description' => $m['description'],
            'status' => $full['exit_code'] === 0 ? 'SURVIVED' : 'KILLED_BY_FULL_SUITE',
            'targeted_wall_ms' => $targeted['wall_ms'],
            'full_wall_ms' => $full['wall_ms'],
            'full_output_tail' => substr($full['output'], -4000),
        ];
    }
} finally {
    file_put_contents($sourcePath, $original);
    clearstatcache(true, $sourcePath);
}

$afterHash = hash_file('sha256', $sourcePath);
$beforeHash = hash('sha256', $original);
if ($afterHash !== $beforeHash) {
    throw new RuntimeException('UsunGps.php nie został przywrócony po mutacjach.');
}

$summary = array_count_values(array_column($results, 'status'));
$output = [
    'generated_at_utc' => gmdate('c'),
    'database' => (string) DB::connection()->getDatabaseName(),
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'php' => PHP_VERSION,
    'source_sha256_before' => $beforeHash,
    'source_sha256_after' => $afterHash,
    'tests_selected' => $tests,
    'baseline' => [
        'exit_code' => $baseline['exit_code'],
        'wall_ms' => $baseline['wall_ms'],
        'output_tail' => substr($baseline['output'], -4000),
    ],
    'summary' => $summary,
    'mutants' => $results,
];

$path = __DIR__.'/mutacje-usungps-wynik.json';
file_put_contents($path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo file_get_contents($path);
