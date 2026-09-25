<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\UsunGps;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GPS znika z KAŻDEGO obrazu w pliku i z AVIF-a bez prefiksu `Exif\0\0`
 * (audyt B5, znalezisko 5).
 *
 * Pomiar z audytu: JPEG z drugim obrazem za `EOI` (układ MPF — podgląd albo
 * mapa wzmocnienia HDR z telefonu), każdy z własnym EXIF-em z GPS-em — dwa
 * wystąpienia współrzędnych przed sanitacją, jedno po niej. AVIF z blokiem
 * TIFF bez prefiksu (spec HEIF to dopuszcza) — GPS zostawał w całości.
 * Oryginał nie jest serwowany, ale trafia do paczki eksportu.
 */
final class OryginalTraciGpsWKazdymObrazieTest extends TestCase
{
    private const SZEROKOSC = [52, 1, 13, 1, 5600, 100];

    private const DLUGOSC = [21, 1, 0, 1, 3600, 100];

    /** Ten sam blok TIFF (producent, data, GPS) co w `OryginalTraciGpsTakzeWPngIWebpTest`. */
    private function tiffZGps(): string
    {
        $make = "TestPhone\x00";
        $dateTime = "2026:09:07 12:00:00\x00";

        $daneOd = 8 + 2 + (3 * 12) + 4;
        $makeOff = $daneOd;
        $dateOff = $makeOff + strlen($make);
        $gpsOff = $dateOff + strlen($dateTime);

        $ifd0 = pack('vvV', 0x010F, 2, strlen($make)).pack('V', $makeOff)
            .pack('vvV', 0x0132, 2, strlen($dateTime)).pack('V', $dateOff)
            .pack('vvV', 0x8825, 4, 1).pack('V', $gpsOff);

        $gpsDaneOd = $gpsOff + 2 + (4 * 12) + 4;
        $lat = pack('V*', ...self::SZEROKOSC);
        $lon = pack('V*', ...self::DLUGOSC);

        $gpsIfd = pack('vvV', 0x0001, 2, 2)."N\x00\x00\x00"
            .pack('vvV', 0x0002, 5, 3).pack('V', $gpsDaneOd)
            .pack('vvV', 0x0003, 2, 2)."E\x00\x00\x00"
            .pack('vvV', 0x0004, 5, 3).pack('V', $gpsDaneOd + strlen($lat));

        return 'II'."\x2A\x00".pack('V', 8)
            .pack('v', 3).$ifd0.pack('V', 0)
            .$make.$dateTime
            .pack('v', 4).$gpsIfd.pack('V', 0)
            .$lat.$lon;
    }

    /** Prawdziwy JPEG z GD z APP1 `Exif\0\0` tuż za SOI. */
    private function jpegZGps(int $szerokosc): string
    {
        $obraz = imagecreatetruecolor($szerokosc, 8);
        ob_start();
        imagejpeg($obraz);
        $jpeg = (string) ob_get_clean();
        imagedestroy($obraz);

        $app1 = "Exif\x00\x00".$this->tiffZGps();

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function ileRazy(string $bajty): int
    {
        return substr_count($bajty, pack('V*', ...self::SZEROKOSC));
    }

    #[Test]
    public function kazdy_obraz_jpeg_z_mpf_traci_wspolrzedne(): void
    {
        $glowny = $this->jpegZGps(16);
        $podglad = $this->jpegZGps(8);
        $plik = $glowny.$podglad;

        // Kontrola dodatnia: oba obrazy naprawdę niosą GPS.
        $this->assertSame(2, $this->ileRazy($plik));
        $this->assertArrayHasKey('GPSLatitude', (array) @exif_read_data('data://image/jpeg;base64,'.base64_encode($podglad)));

        $po = UsunGps::zBajtow($plik);

        $this->assertSame(0, $this->ileRazy($po), 'Współrzędne zostały w którymś obrazie pliku.');
        $this->assertSame(strlen($plik), strlen($po), 'Sanitacja zmieniła długość pliku — przesunięcia MPF przestałyby się zgadzać.');

        $drugi = substr($po, strlen($glowny));
        $exif = (array) @exif_read_data('data://image/jpeg;base64,'.base64_encode($drugi));
        $this->assertArrayNotHasKey('GPSLatitude', $exif);
        $this->assertSame('TestPhone', $exif['Make'] ?? null, 'Producent miał zostać (D-023) — wypada tylko lokalizacja.');
        $this->assertNotFalse(@imagecreatefromstring($po), 'Po sanitacji plik przestał być poprawnym JPEG-iem.');
    }

    #[Test]
    public function avif_z_blokiem_tiff_bez_prefiksu_traci_wspolrzedne(): void
    {
        $tiff = $this->tiffZGps();
        // Kształt elementu `Exif` z HEIF: 4 bajty przesunięcia (0) i od razu TIFF.
        $avif = "\x00\x00\x00\x1CftypavifKuking\x00\x00avifmif1miaf"
            .str_repeat("\x11", 64)
            .pack('N', 0).$tiff
            .str_repeat("\x22", 64);

        $this->assertSame(1, $this->ileRazy($avif));
        $this->assertStringNotContainsString("Exif\x00\x00", $avif, 'Kontrola: ten wariant ma być BEZ prefiksu.');

        $po = UsunGps::zBajtow($avif);

        $this->assertSame(0, $this->ileRazy($po));
        $this->assertSame(strlen($avif), strlen($po));
        $this->assertStringContainsString('TestPhone', $po);
    }

    #[Test]
    public function przypadkowy_naglowek_tiff_bez_struktury_zostaje_nietkniety(): void
    {
        // „II*\0” w danych obrazu bez poprawnego IFD i wskaźnika GPS.
        $avif = "\x00\x00\x00\x1Cftypavif".str_repeat("\x33", 40)."II\x2A\x00\xFF\xFF\xFF\xFF".str_repeat("\x44", 40);

        $this->assertSame($avif, UsunGps::zBajtow($avif));
    }
}
