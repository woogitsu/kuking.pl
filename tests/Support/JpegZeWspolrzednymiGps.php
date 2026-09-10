<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Testowy JPEG ze współrzędnymi GPS w EXIF-ie — i czytanie EXIF-u z bajtów.
 *
 * DLACZEGO OSOBNY PLIK, A NIE PRYWATNA METODA W TEŚCIE
 * Bo pytanie „czy z tej drogi wgrywania naprawdę wypada GPS" zadaje więcej
 * niż jeden test: `OryginalTraciWspolrzedneGpsTest` pyta o samą akcję
 * `StoreUploadedImage`, a `ZdjecieProfiloweNaSkrotyTest` — o trasę zdjęcia
 * profilowego, czyli o to, czy ta akcja jest tam w ogóle wpięta. Druga kopia
 * ręcznie sklejanego EXIF-u to druga okazja, żeby jedna z nich po cichu
 * przestała nieść GPS — a wtedy test „GPS zniknął" świeci na zielono, nie
 * sprawdzając niczego.
 *
 * Dlatego `assertPlikTestowyMaGps()` jest częścią tego traitu: każdy, kto
 * z niego korzysta, ma pod ręką oracle'a na własny plik testowy.
 */
trait JpegZeWspolrzednymiGps
{
    /**
     * Buduje minimalny, PRAWDZIWY JPEG z segmentem APP1/EXIF, w którym
     * siedzą: producent, data i współrzędne GPS. Zbudowany ręcznie, bo
     * repozytorium nie trzyma binarnych plików testowych, a zdjęcie
     * z telefonu z prawdziwym GPS-em byłoby czyimś adresem w historii gita.
     */
    private function jpegZGps(): string
    {
        $maloEndian = 'II'."\x2A\x00".pack('V', 8);

        $make = "TestPhone\x00";
        $dateTime = "2026:09:07 12:00:00\x00";

        // IFD0: Make, DateTime, wskaźnik na GPS.
        $daneOd = 8 + 2 + (3 * 12) + 4;
        $makeOff = $daneOd;
        $dateOff = $makeOff + strlen($make);
        $gpsOff = $dateOff + strlen($dateTime);

        $ifd0 = pack('vvV', 0x010F, 2, strlen($make)).pack('V', $makeOff)
            .pack('vvV', 0x0132, 2, strlen($dateTime)).pack('V', $dateOff)
            .pack('vvV', 0x8825, 4, 1).pack('V', $gpsOff);

        // GPS IFD: dwa odnośniki kierunku (mieszczą się w wpisie) i dwie
        // współrzędne po trzy rationale (24 bajty — leżą poza wpisem, więc
        // to właśnie one są tym „nieodwoływanym śmieciem", który trzeba
        // wyzerować osobno).
        $gpsDaneOd = $gpsOff + 2 + (4 * 12) + 4;
        $lat = pack('VVVVVV', 52, 1, 13, 1, 5600, 100);
        $lon = pack('VVVVVV', 21, 1, 0, 1, 3600, 100);

        $gpsIfd = pack('vvV', 0x0001, 2, 2)."N\x00\x00\x00"
            .pack('vvV', 0x0002, 5, 3).pack('V', $gpsDaneOd)
            .pack('vvV', 0x0003, 2, 2)."E\x00\x00\x00"
            .pack('vvV', 0x0004, 5, 3).pack('V', $gpsDaneOd + strlen($lat));

        $tiff = $maloEndian
            .pack('v', 3).$ifd0.pack('V', 0)
            .$make.$dateTime
            .pack('v', 4).$gpsIfd.pack('V', 0)
            .$lat.$lon;

        $app1 = "Exif\x00\x00".$tiff;

        $obraz = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($obraz, null, 90);
        $jpeg = (string) ob_get_clean();

        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        // APP1 wchodzi bezpośrednio po SOI (`\xFF\xD8`).
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** @return array<string, mixed> */
    private function exif(string $bajty): array
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'gps').'.jpg';
        file_put_contents($sciezka, $bajty);

        $exif = @exif_read_data($sciezka);
        @unlink($sciezka);

        return is_array($exif) ? $exif : [];
    }

    /**
     * Oracle dla wszystkich testów korzystających z tego traitu: bez tego
     * „GPS zniknął" może znaczyć „GPS-u nigdy nie było".
     */
    private function assertPlikTestowyMaGps(string $jpeg): void
    {
        $this->assertArrayHasKey(
            'GPSLatitude',
            $this->exif($jpeg),
            'Plik testowy nie niesie GPS-u, więc żaden test „GPS zniknął" niczego nie sprawdza.',
        );
    }
}
