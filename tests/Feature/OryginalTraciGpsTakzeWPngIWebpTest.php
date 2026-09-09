<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\UsunGps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A6-02: `UsunGps` zostawiał współrzędne w poprawnie zapisanych PNG i WebP.
 *
 * DLACZEGO ISTNIEJĄCY TEST TEGO NIE ZŁAPAŁ
 * `OryginalTraciWspolrzedneGpsTest` bada wyłącznie JPEG — i słusznie, bo to
 * najczęstszy format ze zdjęciem z telefonu. Ale klasa deklarowała cztery
 * kontenery, a znajdowała blok TIFF przez `strpos($bajty, "Exif\0\0")`.
 * Ten sześciobajtowy prefiks jest częścią segmentu APP1 W JPEG-U. W PNG
 * (chunk `eXIf`) i WebP (chunk `EXIF`) dane chunku to zgodnie ze
 * specyfikacją JUŻ SAM BLOK TIFF — prefiksu tam nie ma. Sanitator nie
 * znajdował więc niczego i oddawał plik bajt w bajt, ze współrzędnymi.
 *
 * Znalazł to audyt zewnętrzny, odczytując zapisane pliki niezależnym
 * dekoderem. Test tego nie mógł zauważyć, bo go po prostu nie było.
 *
 * JAK TU WERYFIKUJEMY, SKORO `exif_read_data()` NIE CZYTA PNG ANI WEBP
 * Dwiema drogami naraz, celowo różnymi:
 *
 *   1. BAJTAMI — konkretne bajty współrzędnych, które sami włożyliśmy,
 *      mają zniknąć z pliku, a długość pliku ma zostać ta sama (klasa
 *      zeruje w miejscu, nigdy nie skraca);
 *   2. CZYTNIKIEM EXIF — wyjmujemy z PNG/WebP blok TIFF po sanitacji,
 *      opakowujemy go w minimalny JPEG i czytamy PRAWDZIWYM
 *      `exif_read_data()`. Blok TIFF jest niezależny od kontenera, więc to
 *      jest uczciwe sprawdzenie tego, co naprawdę zmieniliśmy: GPS-u nie
 *      ma, a producent i data zostały.
 *
 * Sama pierwsza droga przepuściłaby sanitator, który uszkodził strukturę
 * TIFF-u; sama druga — taki, który zostawił w pliku nieodwoływane bajty
 * współrzędnych, niewidoczne dla czytnika, ale czytelne dla człowieka.
 */
final class OryginalTraciGpsTakzeWPngIWebpTest extends TestCase
{
    /** Szerokość i długość jako trzy rationale — te bajty mają zniknąć z pliku. */
    private const SZEROKOSC = [52, 1, 13, 1, 5600, 100];

    private const DLUGOSC = [21, 1, 0, 1, 3600, 100];

    /**
     * Blok TIFF z producentem, datą i GPS-em — dokładnie ten sam kształt, co
     * w teście JPEG-a, bo o to chodzi: kontener jest inny, zawartość ta sama.
     */
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
        $lat = $this->wspolrzedna(self::SZEROKOSC);
        $lon = $this->wspolrzedna(self::DLUGOSC);

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

    /** @param list<int> $liczby */
    private function wspolrzedna(array $liczby): string
    {
        return pack('V*', ...$liczby);
    }

    /**
     * PRAWDZIWY PNG z GD, z dołożonym chunkiem `eXIf` przed `IEND`.
     * Dane chunku to sam blok TIFF, BEZ prefiksu `Exif\0\0` — tak jak każe
     * specyfikacja PNG i tak, jak zapisują to prawdziwe narzędzia. Właśnie
     * ten wariant przechodził przez sanitator nietknięty.
     */
    private function pngZGps(): string
    {
        $obraz = imagecreatetruecolor(12, 8);
        ob_start();
        imagepng($obraz);
        $png = (string) ob_get_clean();
        imagedestroy($obraz);

        $tiff = $this->tiffZGps();
        $chunk = pack('N', strlen($tiff)).'eXIf'.$tiff;
        $chunk .= pack('N', crc32('eXIf'.$tiff));

        $iend = strrpos($png, "\x00\x00\x00\x00IEND");
        $this->assertNotFalse($iend, 'GD nie wyprodukowało PNG-a z chunkiem IEND — nie ma gdzie wstawić eXIf.');

        return substr($png, 0, $iend).$chunk.substr($png, $iend);
    }

    /**
     * PRAWDZIWY WebP w formacie ROZSZERZONYM (`VP8X`), bo tylko taki umie
     * nieść metadane. GD produkuje wariant prosty — `RIFF·WEBP·VP8 ` — więc
     * bierzemy z niego sam strumień obrazu i budujemy kontener wokół niego.
     */
    private function webpZGps(): string
    {
        $obraz = imagecreatetruecolor(12, 8);
        ob_start();
        imagewebp($obraz);
        $prosty = (string) ob_get_clean();
        imagedestroy($obraz);

        // Wszystko po `RIFF·rozmiar·WEBP` to gotowy chunk `VP8 `/`VP8L`.
        $obrazowy = substr($prosty, 12);

        $tiff = $this->tiffZGps();

        // VP8X: bajt flag (0x08 = są metadane EXIF), trzy bajty rezerwy,
        // szerokość i wysokość minus jeden, po 24 bity little-endian.
        $vp8x = 'VP8X'.pack('V', 10)
            ."\x08\x00\x00\x00"
            .substr(pack('V', 12 - 1), 0, 3)
            .substr(pack('V', 8 - 1), 0, 3);

        $exif = 'EXIF'.pack('V', strlen($tiff)).$tiff;

        if (strlen($tiff) % 2 === 1) {
            $exif .= "\x00";
        }

        $tresc = 'WEBP'.$vp8x.$obrazowy.$exif;

        return 'RIFF'.pack('V', strlen($tresc)).$tresc;
    }

    /**
     * Wyjmuje blok TIFF z chunku PNG albo WebP i czyta go PRAWDZIWYM
     * czytnikiem EXIF, opakowując w minimalny JPEG. `exif_read_data()` nie
     * zna PNG-a ani WebP-a, ale blok TIFF jest ten sam we wszystkich
     * kontenerach — a to jego zawartość ta klasa zmienia.
     *
     * @return array<string, mixed>
     */
    private function exifZKontenera(string $bajty, string $nazwaChunku): array
    {
        $poz = strpos($bajty, $nazwaChunku);
        $this->assertNotFalse($poz, "W pliku nie ma chunku {$nazwaChunku} — sanitator nie miał prawa go usunąć.");

        $dlugosc = $nazwaChunku === 'eXIf'
            ? (int) unpack('N', substr($bajty, $poz - 4, 4))[1]
            : (int) unpack('V', substr($bajty, $poz + 4, 4))[1];

        $tiff = $nazwaChunku === 'eXIf'
            ? substr($bajty, $poz + 4, $dlugosc)
            : substr($bajty, $poz + 8, $dlugosc);

        $app1 = "Exif\x00\x00".$tiff;

        $obraz = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($obraz, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($obraz);

        $zExifem = substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($app1) + 2).$app1
            .substr($jpeg, 2);

        $sciezka = tempnam(sys_get_temp_dir(), 'gps-kontener').'.jpg';
        file_put_contents($sciezka, $zExifem);
        $exif = @exif_read_data($sciezka);
        @unlink($sciezka);

        return is_array($exif) ? $exif : [];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function kontenery(): array
    {
        return [
            'PNG (chunk eXIf)' => ['pngZGps', 'eXIf'],
            'WebP (chunk EXIF)' => ['webpZGps', 'EXIF'],
        ];
    }

    /**
     * KONTROLA METODY POMIARU. Bez niej wszystkie asercje niżej przechodziłyby
     * także wtedy, gdyby plik testowy w ogóle nie miał GPS-u — a wtedy ten
     * plik udowadniałby dokładnie tyle, ile udowadniał brak testu.
     */
    #[Test]
    #[DataProvider('kontenery')]
    public function plik_testowy_naprawde_ma_wspolrzedne(string $budowniczy, string $chunk): void
    {
        $bajty = $this->{$budowniczy}();

        $this->assertStringContainsString($this->wspolrzedna(self::SZEROKOSC), $bajty,
            'Bajtów szerokości nie ma w pliku testowym.');
        $this->assertStringContainsString($this->wspolrzedna(self::DLUGOSC), $bajty,
            'Bajtów długości nie ma w pliku testowym.');

        $exif = $this->exifZKontenera($bajty, $chunk);

        $this->assertArrayHasKey('GPSLatitude', $exif,
            'Czytnik EXIF nie widzi GPS-u w pliku testowym — reszta tego pliku nie badałaby niczego.');
    }

    #[Test]
    #[DataProvider('kontenery')]
    public function wspolrzedne_znikaja_z_pliku(string $budowniczy, string $chunk): void
    {
        $przed = $this->{$budowniczy}();
        $po = UsunGps::zBajtow($przed);

        // To jest właściwe znalezisko A6-02: przed poprawką te dwie asercje
        // padały, bo `$po` było identyczne z `$przed`.
        $this->assertStringNotContainsString($this->wspolrzedna(self::SZEROKOSC), $po,
            'Bajty szerokości geograficznej zostały w zapisanym oryginale.');
        $this->assertStringNotContainsString($this->wspolrzedna(self::DLUGOSC), $po,
            'Bajty długości geograficznej zostały w zapisanym oryginale.');

        $exif = $this->exifZKontenera($po, $chunk);

        $this->assertArrayNotHasKey('GPSLatitude', $exif, 'Czytnik EXIF nadal widzi szerokość geograficzną.');
        $this->assertArrayNotHasKey('GPSLongitude', $exif, 'Czytnik EXIF nadal widzi długość geograficzną.');
    }

    /**
     * D-023 mówi wprost: wypada TYLKO lokalizacja. Aparat i data to
     * informacja o zdjęciu, którą właściciel może chcieć odzyskać z eksportu.
     */
    #[Test]
    #[DataProvider('kontenery')]
    public function reszta_metadanych_zostaje(string $budowniczy, string $chunk): void
    {
        $exif = $this->exifZKontenera(UsunGps::zBajtow($this->{$budowniczy}()), $chunk);

        $this->assertSame('TestPhone', $exif['Make'] ?? null, 'Zniknął producent — miała wypaść wyłącznie lokalizacja.');
        $this->assertSame('2026:09:07 12:00:00', $exif['DateTime'] ?? null, 'Zniknęła data — miała wypaść wyłącznie lokalizacja.');
    }

    /**
     * Zerowanie W MIEJSCU, nigdy skracanie — wyrzucenie choćby jednego bajtu
     * unieważniłoby wszystkie przesunięcia w TIFF-ie i długości zapisane
     * w kontenerze.
     */
    #[Test]
    #[DataProvider('kontenery')]
    public function plik_ma_te_sama_dlugosc_i_zostaje_poprawny(string $budowniczy, string $chunk): void
    {
        $przed = $this->{$budowniczy}();
        $po = UsunGps::zBajtow($przed);

        $this->assertSame(strlen($przed), strlen($po), 'Zmieniła się długość pliku — sanitator skrócił plik zamiast wyzerować bajty.');

        if ($chunk === 'eXIf') {
            $poz = strpos($po, 'eXIf');
            $this->assertNotFalse($poz);

            $dlugosc = (int) unpack('N', substr($po, $poz - 4, 4))[1];
            $zapisana = (int) unpack('N', substr($po, $poz + 4 + $dlugosc, 4))[1];

            $this->assertSame(crc32(substr($po, $poz, 4 + $dlugosc)), $zapisana,
                'Suma CRC chunku eXIf się nie zgadza — przeglądarka uzna plik za uszkodzony. Zdjęcie z GPS-em to problem prywatności, zdjęcie uszkodzone to utrata pamiątki.');
        }

        // Obraz nadal daje się odczytać — czyli nie ruszyliśmy pikseli.
        $rozmiar = @getimagesizefromstring($po);
        $this->assertIsArray($rozmiar, 'Plik przestał być czytelnym obrazem.');
        $this->assertSame(12, $rozmiar[0]);
        $this->assertSame(8, $rozmiar[1]);
    }

    /**
     * Kontrola w DRUGĄ stronę: plik bez metadanych ma wrócić bajt w bajt.
     * Bez niej sanitator zerujący wszystko na oślep przechodziłby wszystkie
     * asercje wyżej.
     */
    #[Test]
    public function png_bez_chunku_exif_wraca_bez_zmiany(): void
    {
        $obraz = imagecreatetruecolor(12, 8);
        ob_start();
        imagepng($obraz);
        $png = (string) ob_get_clean();
        imagedestroy($obraz);

        $this->assertStringNotContainsString('eXIf', $png, 'GD nagle zapisuje eXIf — ten przypadek trzeba przemyśleć od nowa.');
        $this->assertSame($png, UsunGps::zBajtow($png));
    }
}
