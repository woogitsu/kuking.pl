<?php

declare(strict_types=1);

/**
 * A7-2 / 7af3879 — niezależne sondy bajtowe dla przypadków wskazanych
 * w zleceniu. Nie korzystają z builderów testowych klasy aplikacji.
 *
 * Uruchomienie:
 *   php docs/zlecenia/dowody-a7/pomiar-usungps-krawedzie.php
 */

use App\Domain\Media\UsunGps;

require dirname(__DIR__, 3).'/vendor/autoload.php';

const A7_LAT = [52, 1, 13, 1, 5600, 100];
const A7_LON = [21, 1, 0, 1, 3600, 100];

function a7Rational(array $v, bool $little = true): string
{
    $format = $little ? 'V*' : 'N*';

    return pack($format, ...$v);
}

function a7TiffGps(bool $little = true): string
{
    $short = static fn (int $v): string => pack($little ? 'v' : 'n', $v);
    $long = static fn (int $v): string => pack($little ? 'V' : 'N', $v);
    $entry = static fn (int $tag, int $type, int $count, int $value) =>
        $short($tag).$short($type).$long($count).$long($value);

    $make = "A7Phone\x00";
    $date = "2026:09:09 17:00:00\x00";
    $ifd0Offset = 8;
    $dataStart = $ifd0Offset + 2 + (3 * 12) + 4;
    $makeOffset = $dataStart;
    $dateOffset = $makeOffset + strlen($make);
    $gpsOffset = $dateOffset + strlen($date);
    $gpsDataOffset = $gpsOffset + 2 + (4 * 12) + 4;
    $lat = a7Rational(A7_LAT, $little);
    $lon = a7Rational(A7_LON, $little);

    $ifd0 = $short(3)
        .$entry(0x010F, 2, strlen($make), $makeOffset)
        .$entry(0x0132, 2, strlen($date), $dateOffset)
        .$entry(0x8825, 4, 1, $gpsOffset)
        .$long(0);

    // N/E mieszczą się w czterobajtowym polu wartości i nie mają offsetu.
    $latRef = $little ? "N\x00\x00\x00" : "N\x00\x00\x00";
    $lonRef = $little ? "E\x00\x00\x00" : "E\x00\x00\x00";
    $gps = $short(4)
        .$short(0x0001).$short(2).$long(2).$latRef
        .$entry(0x0002, 5, 3, $gpsDataOffset)
        .$short(0x0003).$short(2).$long(2).$lonRef
        .$entry(0x0004, 5, 3, $gpsDataOffset + strlen($lat))
        .$long(0);

    $header = $little ? "II\x2A\x00" : "MM\x00\x2A";

    return $header.$long($ifd0Offset).$ifd0.$make.$date.$gps.$lat.$lon;
}

function a7PngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function a7BasePng(int $width = 48, int $height = 32): string
{
    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        throw new RuntimeException('Nie udało się utworzyć obrazu GD.');
    }
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, 120, 80, 40));
    ob_start();
    imagepng($im, null, 6);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

/** @return list<array{type:string,start:int,data_start:int,length:int,crc_ok:bool}> */
function a7PngChunks(string $png): array
{
    if (! str_starts_with($png, "\x89PNG\r\n\x1A\n")) {
        return [];
    }
    $out = [];
    $pos = 8;
    $len = strlen($png);
    while ($pos + 12 <= $len) {
        $n = unpack('N', substr($png, $pos, 4));
        if ($n === false) {
            break;
        }
        $size = (int) $n[1];
        if ($pos + 12 + $size > $len) {
            break;
        }
        $type = substr($png, $pos + 4, 4);
        $stored = unpack('N', substr($png, $pos + 8 + $size, 4));
        $actual = crc32(substr($png, $pos + 4, 4 + $size));
        $out[] = [
            'type' => $type,
            'start' => $pos,
            'data_start' => $pos + 8,
            'length' => $size,
            'crc_ok' => $stored !== false && (int) $stored[1] === $actual,
        ];
        $pos += 12 + $size;
        if ($type === 'IEND') {
            break;
        }
    }

    return $out;
}

function a7PngInsertBeforeType(string $png, string $before, string $chunk): string
{
    foreach (a7PngChunks($png) as $c) {
        if ($c['type'] === $before) {
            return substr($png, 0, $c['start']).$chunk.substr($png, $c['start']);
        }
    }

    throw new RuntimeException("Nie znaleziono chunku {$before}.");
}

function a7PngInsertAfterLastIdat(string $png, string $chunk): string
{
    $chunks = a7PngChunks($png);
    $after = null;
    foreach ($chunks as $c) {
        if ($c['type'] === 'IDAT') {
            $after = $c['start'] + 12 + $c['length'];
        }
    }
    if ($after === null) {
        throw new RuntimeException('Brak IDAT.');
    }

    return substr($png, 0, $after).$chunk.substr($png, $after);
}

function a7ImageReadable(string $bytes): bool
{
    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        return false;
    }
    imagedestroy($im);

    return true;
}

function a7GpsBytesGone(string $bytes, bool $little = true): bool
{
    return ! str_contains($bytes, a7Rational(A7_LAT, $little))
        && ! str_contains($bytes, a7Rational(A7_LON, $little));
}

function a7BaseWebp(): string
{
    $im = imagecreatetruecolor(48, 32);
    ob_start();
    imagewebp($im, null, 80);
    $simple = (string) ob_get_clean();
    imagedestroy($im);

    return substr($simple, 12);
}

function a7WebpExtendedWithOddChunkAndExif(string $tiff): string
{
    $vp8xData = "\x08\x00\x00\x00".substr(pack('V', 47), 0, 3).substr(pack('V', 31), 0, 3);
    $vp8x = 'VP8X'.pack('V', 10).$vp8xData;
    $odd = 'JUNK'.pack('V', 3).'abc'."\x00";
    $image = a7BaseWebp();
    $exif = 'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 ? "\x00" : '');
    $payload = 'WEBP'.$vp8x.$odd.$image.$exif;

    return 'RIFF'.pack('V', strlen($payload)).$payload;
}

function a7JpegWithNonExifApp1ThenExif(string $tiff): string
{
    $im = imagecreatetruecolor(48, 32);
    ob_start();
    imagejpeg($im, null, 85);
    $jpeg = (string) ob_get_clean();
    imagedestroy($im);

    $xmp = "http://ns.adobe.com/xap/1.0/\x00<A7-xmp/>";
    $app1Xmp = "\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp;
    $exif = "Exif\x00\x00".$tiff;
    $app1Exif = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1Xmp.$app1Exif.substr($jpeg, 2);
}

function a7RawProfileZtxt(string $tiff): string
{
    return "Raw profile type exif\x00\x00".gzcompress($tiff, 6);
}

function a7ExtractZtxtTiff(string $png): ?string
{
    foreach (a7PngChunks($png) as $c) {
        if ($c['type'] !== 'zTXt') {
            continue;
        }
        $data = substr($png, $c['data_start'], $c['length']);
        $nul = strpos($data, "\x00");
        if ($nul === false || substr($data, 0, $nul) !== 'Raw profile type exif') {
            continue;
        }
        // NUL kończący keyword, potem compression method = 0.
        $compressed = substr($data, $nul + 2);
        $decoded = @gzuncompress($compressed);

        return is_string($decoded) ? $decoded : null;
    }

    return null;
}

/**
 * Poprawny PNG, ale TIFF w eXIf ma złośliwy offset wartości GPS prowadzący
 * poza własny chunk, dokładnie do danych następnego IDAT. PNG jako kontener
 * przed sanitacją jest poprawny (wszystkie CRC zgadzają się).
 */
function a7PngWithGpsPointerIntoIdat(): array
{
    $base = a7BasePng(160, 120);
    $idat = null;
    foreach (a7PngChunks($base) as $c) {
        if ($c['type'] === 'IDAT' && $c['length'] >= 32) {
            $idat = $c;
            break;
        }
    }
    if ($idat === null) {
        throw new RuntimeException('PNG testowy nie ma IDAT >= 32 B.');
    }

    // TIFF: header (8), IFD0 z jednym GPS pointerem (18), GPS IFD (18) = 44 B.
    // eXIf ma 12 B narzutu, więc po wstawieniu tuż przed IDAT dane IDAT
    // zaczynają się 56 bajtów od początku TIFF-u.
    $gpsIfdOffset = 26;
    $targetOffset = 56;
    $tiff = "II\x2A\x00".pack('V', 8)
        .pack('v', 1)
        .pack('vvV', 0x8825, 4, 1).pack('V', $gpsIfdOffset)
        .pack('V', 0)
        .pack('v', 1)
        .pack('vvV', 0x0002, 5, 3).pack('V', $targetOffset)
        .pack('V', 0);
    if (strlen($tiff) !== 44) {
        throw new RuntimeException('Nieoczekiwana długość TIFF sondy.');
    }

    $png = a7PngInsertBeforeType($base, 'IDAT', a7PngChunk('eXIf', $tiff));
    $chunks = a7PngChunks($png);
    $eXIf = null;
    $newIdat = null;
    foreach ($chunks as $c) {
        if ($c['type'] === 'eXIf') {
            $eXIf = $c;
        }
        if ($c['type'] === 'IDAT' && $newIdat === null) {
            $newIdat = $c;
        }
    }
    if ($eXIf === null || $newIdat === null) {
        throw new RuntimeException('Nie zbudowano eXIf/IDAT.');
    }
    $calculated = $newIdat['data_start'] - $eXIf['data_start'];
    if ($calculated !== $targetOffset) {
        throw new RuntimeException("Offset do IDAT wynosi {$calculated}, oczekiwano {$targetOffset}.");
    }

    return [$png, $newIdat];
}

$wyniki = [];

// eXIf po IDAT.
$tiff = a7TiffGps();
$pngAfterIdat = a7PngInsertAfterLastIdat(a7BasePng(), a7PngChunk('eXIf', $tiff));
$pngAfter = UsunGps::zBajtow($pngAfterIdat);
$wyniki['png_exif_after_idat'] = [
    'gps_removed' => a7GpsBytesGone($pngAfter),
    'same_length' => strlen($pngAfter) === strlen($pngAfterIdat),
    'all_crc_ok' => ! in_array(false, array_column(a7PngChunks($pngAfter), 'crc_ok'), true),
    'readable' => a7ImageReadable($pngAfter),
];

// WebP z poprzedzającym chunkiem nieparzystej długości.
$webp = a7WebpExtendedWithOddChunkAndExif($tiff);
$webpAfter = UsunGps::zBajtow($webp);
$wyniki['webp_odd_chunk_before_exif'] = [
    'gps_removed' => a7GpsBytesGone($webpAfter),
    'same_length' => strlen($webpAfter) === strlen($webp),
    'readable' => a7ImageReadable($webpAfter),
];

// JPEG z APP1/XMP przed prawdziwym APP1/EXIF.
$jpeg = a7JpegWithNonExifApp1ThenExif($tiff);
$jpegAfter = UsunGps::zBajtow($jpeg);
$wyniki['jpeg_non_exif_app1_before_exif'] = [
    'gps_removed' => a7GpsBytesGone($jpegAfter),
    'xmp_preserved' => str_contains($jpegAfter, '<A7-xmp/>'),
    'same_length' => strlen($jpegAfter) === strlen($jpeg),
    'readable' => a7ImageReadable($jpegAfter),
];

// TIFF big-endian, żeby niezależnie pokryć drugą gałąź parsera.
$tiffBe = a7TiffGps(false);
$pngBe = a7PngInsertAfterLastIdat(a7BasePng(), a7PngChunk('eXIf', $tiffBe));
$pngBeAfter = UsunGps::zBajtow($pngBe);
$wyniki['png_big_endian_tiff'] = [
    'gps_removed' => a7GpsBytesGone($pngBeAfter, false),
    'same_length' => strlen($pngBeAfter) === strlen($pngBe),
    'all_crc_ok' => ! in_array(false, array_column(a7PngChunks($pngBeAfter), 'crc_ok'), true),
];

// eXIf + zTXt: eXIf ma zostać oczyszczony, zTXt jest jawnym znanym zakresem poza klasą.
$ztxt = a7PngChunk('zTXt', a7RawProfileZtxt($tiff));
$both = a7PngInsertAfterLastIdat(a7BasePng(), a7PngChunk('eXIf', $tiff).$ztxt);
$bothAfter = UsunGps::zBajtow($both);
$rawAfter = a7ExtractZtxtTiff($bothAfter);
$wyniki['png_exif_and_ztxt_known_limit'] = [
    'exif_gps_removed' => a7GpsBytesGone(substr($bothAfter, 0, (int) strpos($bothAfter, 'zTXt'))),
    'ztxt_decodes' => $rawAfter !== null,
    'ztxt_still_contains_lat' => $rawAfter !== null && str_contains($rawAfter, a7Rational(A7_LAT)),
    'ztxt_still_contains_lon' => $rawAfter !== null && str_contains($rawAfter, a7Rational(A7_LON)),
];

// Ucięty i absurdalnie długi chunk: funkcja nie może rzucić wyjątku ani zmienić długości.
$truncated = "\x89PNG\r\n\x1A\n".pack('N', 100).'eXIf'."II\x2A\x00".pack('V', 8)."\x01";
try {
    $truncatedAfter = UsunGps::zBajtow($truncated);
    $wyniki['png_truncated_exif_chunk'] = [
        'threw' => false,
        'changed' => $truncatedAfter !== $truncated,
        'same_length' => strlen($truncatedAfter) === strlen($truncated),
    ];
} catch (Throwable $e) {
    $wyniki['png_truncated_exif_chunk'] = ['threw' => true, 'error' => get_class($e).': '.$e->getMessage()];
}

$oversized = "\x89PNG\r\n\x1A\n".pack('N', 0x7fffffff).'JUNK'.'abc';
try {
    $oversizedAfter = UsunGps::zBajtow($oversized);
    $wyniki['png_declared_length_beyond_file'] = [
        'threw' => false,
        'unchanged' => $oversizedAfter === $oversized,
    ];
} catch (Throwable $e) {
    $wyniki['png_declared_length_beyond_file'] = ['threw' => true, 'error' => get_class($e).': '.$e->getMessage()];
}

// Adwersarialny TIFF z offsetem poza eXIf, do danych obrazu.
[$cross, $idatBefore] = a7PngWithGpsPointerIntoIdat();
$crossBeforeData = substr($cross, $idatBefore['data_start'], 24);
$crossAfter = UsunGps::zBajtow($cross);
$idatAfter = null;
foreach (a7PngChunks($crossAfter) as $c) {
    if ($c['type'] === 'IDAT') {
        $idatAfter = $c;
        break;
    }
}
$crossAfterData = $idatAfter === null ? null : substr($crossAfter, $idatAfter['data_start'], 24);
$wyniki['png_tiff_offset_crosses_into_idat'] = [
    'before_all_crc_ok' => ! in_array(false, array_column(a7PngChunks($cross), 'crc_ok'), true),
    'before_readable' => a7ImageReadable($cross),
    'idat_first_24_changed' => $crossAfterData !== null && $crossAfterData !== $crossBeforeData,
    'idat_first_24_zeroed' => $crossAfterData === str_repeat("\x00", 24),
    'after_all_crc_ok' => ! in_array(false, array_column(a7PngChunks($crossAfter), 'crc_ok'), true),
    'after_idat_crc_ok' => $idatAfter['crc_ok'] ?? null,
    'after_readable' => a7ImageReadable($crossAfter),
    'same_length' => strlen($crossAfter) === strlen($cross),
];

$wynik = [
    'generated_at_utc' => gmdate('c'),
    'php' => PHP_VERSION,
    'usungps_sha256' => hash_file('sha256', dirname(__DIR__, 3).'/app/Domain/Media/UsunGps.php'),
    'cases' => $wyniki,
];

$path = __DIR__.'/usungps-krawedzie-wynik.json';
file_put_contents($path, json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo file_get_contents($path);
