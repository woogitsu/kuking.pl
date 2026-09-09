<?php

declare(strict_types=1);

/**
 * Szybki przebieg A7-1: każdy mutant naprawdę podmienia `UsunGps.php` i
 * uruchamia wszystkie testy, których nazwa lub treść odwołuje się do GPS /
 * UsunGps. Pełny harness `mutacje-usungps.php` dodatkowo potwierdza survivory
 * całym zestawem; ten plik służy do szybkiej klasyfikacji, gdy pełny przebieg
 * jest kosztowny.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ((string) DB::connection()->getDatabaseName() !== 'kuking_test_a7_mutacje') {
    fwrite(STDERR, "ODMOWA: oczekiwano kuking_test_a7_mutacje.\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$path = $root.'/app/Domain/Media/UsunGps.php';
$original = (string) file_get_contents($path);

$tests = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests'));
foreach ($it as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $content = (string) file_get_contents($file->getPathname());
    if (stripos($file->getFilename(), 'gps') !== false || stripos($file->getFilename(), 'wspolrzed') !== false || str_contains($content, 'UsunGps')) {
        $tests[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
$tests = array_values(array_unique($tests));
sort($tests);
if ($tests === []) {
    throw new RuntimeException('Brak wybranych testów.');
}

$mutants = [
    ['M01_png_detector_off', 'PNG detector off', 'if (str_starts_with($bajty, self::SYGNATURA_PNG)) {', 'if (false) {'],
    ['M02_webp_detector_off', 'WebP detector off', "if (str_starts_with(\$bajty, 'RIFF') && substr(\$bajty, 8, 4) === 'WEBP') {", 'if (false) {'],
    ['M03_jpeg_detector_off', 'JPEG detector off', 'if (str_starts_with($bajty, "\\xFF\\xD8")) {', 'if (false) {'],
    ['M04_png_chunk_name', 'eXIf -> eXIz', "if (\$typ === 'eXIf') {", "if (\$typ === 'eXIz') {"],
    ['M05_webp_chunk_name', 'EXIF -> EXIz', "if (\$typ === 'EXIF') {", "if (\$typ === 'EXIz') {"],
    ['M06_webp_odd_padding', 'bez paddingu RIFF', '$nastepny = $poz + 8 + $dlugosc + ($dlugosc % 2);', '$nastepny = $poz + 8 + $dlugosc;'],
    ['M07_jpeg_sos_marker', 'SOS DA -> DB', 'if ($znacznik === 0xDA) {', 'if ($znacznik === 0xDB) {'],
    ['M08_jpeg_app1_marker', 'APP1 E1 -> E2', 'if ($znacznik === 0xE1 && substr($bajty, $poz + 4, strlen(self::NAGLOWEK)) === self::NAGLOWEK) {', 'if ($znacznik === 0xE2 && substr($bajty, $poz + 4, strlen(self::NAGLOWEK)) === self::NAGLOWEK) {'],
    ['M09_little_endian', 'II jako big-endian', "'II' => true,", "'II' => false,"],
    ['M10_big_endian', 'MM jako little-endian', "'MM' => false,", "'MM' => true,"],
    ['M11_magic', '42 -> 43', 'if (self::short($bajty, $tiff + 2, $maloEndian) !== 42) {', 'if (self::short($bajty, $tiff + 2, $maloEndian) !== 43) {'],
    ['M12_gps_tag', '0x8825 -> 0x8824', 'private const TAG_GPS_IFD = 0x8825;', 'private const TAG_GPS_IFD = 0x8824;'],
    ['M13_inline_boundary', '<=4 -> <4', 'if ($dlugosc <= 4) {', 'if ($dlugosc < 4) {'],
    ['M14_png_crc_bypass', 'pomija poprawę CRC', 'return $chunkPng === null', 'return true'],
    ['M15_prefix_skip', 'nie pomija Exif\\0\\0', "return substr(\$bajty, \$od, strlen(self::NAGLOWEK)) === self::NAGLOWEK\n            ? \$od + strlen(self::NAGLOWEK)\n            : \$od;", 'return $od;'],
    ['M16_crc_boundary', '> długość -> >=', 'if ($koniecDanych + 4 > strlen($bajty)) {', 'if ($koniecDanych + 4 >= strlen($bajty)) {'],
];

$run = static function (array $files) use ($root): array {
    $cmd = array_merge(['php', 'artisan', 'test', '--compact'], $files);
    $escaped = implode(' ', array_map('escapeshellarg', $cmd));
    $start = hrtime(true);
    exec('cd '.escapeshellarg($root).' && '.$escaped.' 2>&1', $lines, $code);

    return ['exit_code' => $code, 'wall_ms' => round((hrtime(true) - $start) / 1_000_000, 3), 'tail' => substr(implode("\n", $lines), -2500)];
};

$baseline = $run($tests);
if ($baseline['exit_code'] !== 0) {
    throw new RuntimeException('Baseline nie jest zielony: '.$baseline['tail']);
}

$results = [];
try {
    foreach ($mutants as [$id, $desc, $from, $to]) {
        $count = substr_count($original, $from);
        if ($count !== 1) {
            $results[] = ['id' => $id, 'description' => $desc, 'status' => 'INVALID', 'occurrences' => $count];
            continue;
        }
        file_put_contents($path, str_replace($from, $to, $original, $replaced));
        if ($replaced !== 1) {
            throw new RuntimeException("Mutant {$id}: zła liczba podmian.");
        }
        $r = $run($tests);
        file_put_contents($path, $original);
        $results[] = ['id' => $id, 'description' => $desc, 'status' => $r['exit_code'] === 0 ? 'SURVIVED_TARGETED' : 'KILLED', 'wall_ms' => $r['wall_ms'], 'tail' => $r['tail']];
    }
} finally {
    file_put_contents($path, $original);
}

$out = [
    'generated_at_utc' => gmdate('c'),
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'php' => PHP_VERSION,
    'source_sha256_before' => hash('sha256', $original),
    'source_sha256_after' => hash_file('sha256', $path),
    'tests_selected' => $tests,
    'baseline' => $baseline,
    'summary' => array_count_values(array_column($results, 'status')),
    'mutants' => $results,
];

file_put_contents(__DIR__.'/mutacje-usungps-szybkie-wynik.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
echo file_get_contents(__DIR__.'/mutacje-usungps-szybkie-wynik.json');
