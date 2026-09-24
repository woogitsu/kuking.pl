<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\UsunGps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\TestCase;

/**
 * Oryginał traci współrzędne zapisane w XMP, nie tylko w EXIF-ie (issue #1004).
 *
 * `UsunGps` zerowała wyłącznie pod-IFD GPS w bloku TIFF. XMP to drugi,
 * niezależny zapis metadanych z własnymi polami `exif:GPSLatitude`,
 * `exif:GPSLongitude`, `GPSDest*` i strukturami lokalizacji IPTC — i przechodził
 * przez sanitator bajt w bajt, prosto do prywatnego oryginału i paczki RODO.
 *
 * FIXTURE'Y ZROBIŁA NIEZALEŻNA BIBLIOTEKA. Pliki w `tests/Fixtures/xmp-gps/`
 * zapisał Pillow (`zrob-fixtury.py` obok nich), a nie kod tego repozytorium —
 * więc kontener XMP jest taki, jaki robi prawdziwe narzędzie: APP1 w JPEG-u,
 * `iTXt` (także skompresowany) w PNG, chunk `XMP ` w WebP, element w AVIF-ie.
 * Test na pliku zbudowanym przez nas samych sprawdzałby tylko, że nasz parser
 * zgadza się z naszym budowniczym.
 *
 * Pierwszy przypadek sprawdza same fixture'y: bez niego „współrzędnych nie ma
 * po sanitacji" przechodziłoby też na pliku, w którym nie było ich nigdy.
 */
class OryginalTraciGpsZXmpTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;

    /** Syntetyczne współrzędne z fixture'ów — środek Bałtyku, niczyje. */
    private const SZEROKOSC = '55,31.4159N';

    private const DLUGOSC = '17,27.1828E';

    /**
     * @return array<string, array{0: string}>
     */
    public static function pliki(): array
    {
        return [
            'jpeg (APP1)' => ['xmp-gps.jpg'],
            'png (iTXt)' => ['xmp-gps.png'],
            'png (skompresowany iTXt)' => ['xmp-gps-skompresowany.png'],
            'webp (chunk XMP)' => ['xmp-gps.webp'],
            'avif (element XMP)' => ['xmp-gps.avif'],
        ];
    }

    #[DataProvider('pliki')]
    public function test_fixture_naprawde_niesie_wspolrzedne_w_xmp(string $plik): void
    {
        $xmp = $this->xmpZPliku($this->fixture($plik));

        $this->assertNotNull($xmp, "Fixture {$plik} nie ma pakietu XMP — reszta testów sprawdzałaby nic.");
        $this->assertStringContainsString('exif:GPSLatitude="'.self::SZEROKOSC.'"', $xmp);
        $this->assertStringContainsString('<exif:GPSDestLatitude>'.self::SZEROKOSC.'<', $xmp);
        $this->assertStringContainsString('<exif:GPSLongitude>'.self::DLUGOSC.'<', $xmp);
    }

    #[DataProvider('pliki')]
    public function test_wspolrzedne_z_xmp_znikaja_a_plik_zostaje_obrazem(string $plik): void
    {
        $przed = $this->fixture($plik);
        $po = UsunGps::zBajtow($przed);

        // Czytnik pakietu XMP po stronie testu: pakietu, który da się odczytać
        // jako XMP, już nie ma.
        $this->assertNull($this->xmpZPliku($po), "W {$plik} został pakiet XMP.");

        // I surowe bajty: współrzędne nie zostają jako nieodwoływany śmieć.
        foreach ([self::SZEROKOSC, self::DLUGOSC, 'GPSLatitude', 'GPSLongitude', 'GPSDest'] as $szukane) {
            $this->assertStringNotContainsString($szukane, $po, "W {$plik} zostało „{$szukane}\".");
        }

        $this->assertSame(strlen($przed), strlen($po), 'Plik zmienił długość — a wolno nam tylko nadpisywać bajty w miejscu.');

        $rozmiar = getimagesizefromstring($po);
        $this->assertIsArray($rozmiar, "{$plik} po sanitacji przestał być czytelnym obrazem.");
        $this->assertSame([64, 48], [$rozmiar[0], $rozmiar[1]]);
    }

    /**
     * PNG ma sumę CRC w KAŻDYM chunku. Przeglądarka odrzuca plik z błędną
     * sumą, więc przepisany chunk tekstowy musi dostać nową — sprawdzamy
     * wszystkie, niezależnie od `UsunGps`.
     */
    public function test_png_po_sanitacji_ma_poprawne_sumy_crc(): void
    {
        foreach (['xmp-gps.png', 'xmp-gps-skompresowany.png'] as $plik) {
            $po = UsunGps::zBajtow($this->fixture($plik));
            $poz = 8;

            while ($poz + 12 <= strlen($po)) {
                $dlugosc = unpack('N', substr($po, $poz, 4))[1];
                $typIDane = substr($po, $poz + 4, 4 + $dlugosc);
                $crc = unpack('N', substr($po, $poz + 8 + $dlugosc, 4))[1];

                $this->assertSame(crc32($typIDane), $crc, 'Zła suma CRC chunku '.substr($typIDane, 0, 4)." w {$plik}.");

                $poz += 12 + $dlugosc;
            }

            $this->assertNotFalse(@imagecreatefromstring($po), "GD nie dekoduje {$plik} po sanitacji.");
        }
    }

    /**
     * XMP wypada w całości, EXIF — poza GPS-em — zostaje (D-023): aparat
     * i data to informacja o zdjęciu, którą właściciel może chcieć odzyskać.
     */
    public function test_jpeg_z_gps_w_exif_i_w_xmp_traci_oba_a_reszta_exif_zostaje(): void
    {
        $jpeg = $this->jpegZGpsWObuMiejscach();

        $this->assertArrayHasKey('GPSLatitude', $this->exif($jpeg), 'Plik testowy nie ma GPS-u w EXIF-ie.');
        $this->assertNotNull($this->xmpZPliku($jpeg), 'Plik testowy nie ma XMP.');

        $po = UsunGps::zBajtow($jpeg);
        $exif = $this->exif($po);

        $this->assertArrayNotHasKey('GPSLatitude', $exif);
        $this->assertNull($this->xmpZPliku($po));
        $this->assertStringNotContainsString(self::SZEROKOSC, $po);
        $this->assertSame('TestPhone', $exif['Make'] ?? null, 'Producent aparatu zniknął razem z XMP.');
        $this->assertSame('2026:09:07 12:00:00', $exif['DateTime'] ?? null, 'Data zdjęcia zniknęła razem z XMP.');
    }

    /**
     * Test integracyjny: parser może być doskonały i nigdy niewołany. Tu
     * idziemy przez prawdziwe wgranie i czytamy bajty, które NAPRAWDĘ leżą
     * na dysku oryginałów.
     */
    public function test_wgrane_zdjecie_z_xmp_lezy_w_buckecie_bez_wspolrzednych(): void
    {
        Queue::fake();
        Storage::fake('testowy');
        Storage::fake('testowy_publiczny');
        config([
            'kuking.media.disk' => 'testowy',
            'kuking.media.public_disk' => 'testowy_publiczny',
        ]);

        $sciezka = tempnam(sys_get_temp_dir(), 'xmp').'.jpg';
        file_put_contents($sciezka, $this->fixture('xmp-gps.jpg'));

        try {
            $media = app(StoreUploadedImage::class)->handle(
                owner: $this->user('kucharka'),
                file: new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true),
            );
        } finally {
            @unlink($sciezka);
        }

        $wBuckecie = Storage::disk('testowy')->get($media->object_key);

        $this->assertNotNull($wBuckecie, 'Oryginał nie trafił do bucketu.');
        $this->assertNull($this->xmpZPliku($wBuckecie), 'Oryginał leży w buckecie z pakietem XMP.');
        $this->assertStringNotContainsString(self::SZEROKOSC, $wBuckecie, 'Współrzędne z XMP leżą w buckecie.');
        $this->assertSame(hash('sha256', $wBuckecie), $media->checksum_sha256);
    }

    private function fixture(string $plik): string
    {
        $bajty = file_get_contents(base_path('tests/Fixtures/xmp-gps/'.$plik));

        $this->assertIsString($bajty, "Brak fixture'u {$plik}.");

        return $bajty;
    }

    /**
     * Pakiet XMP z pliku — tak, jak szuka go czytnik: ramka `xpacket` albo
     * element `x:xmpmeta`, w PNG także w skompresowanym `iTXt`.
     */
    private function xmpZPliku(string $bajty): ?string
    {
        $kandydaci = [$bajty];

        if (str_starts_with($bajty, "\x89PNG")) {
            $poz = 8;

            while ($poz + 12 <= strlen($bajty)) {
                $dlugosc = unpack('N', substr($bajty, $poz, 4))[1];
                $typ = substr($bajty, $poz + 4, 4);
                $dane = substr($bajty, $poz + 8, $dlugosc);

                if ($typ === 'iTXt' && str_starts_with($dane, "XML:com.adobe.xmp\x00\x01")) {
                    $tekst = substr($dane, strlen("XML:com.adobe.xmp\x00") + 2);
                    $tekst = substr($tekst, strpos($tekst, "\x00") + 1);
                    $tekst = substr($tekst, strpos($tekst, "\x00") + 1);
                    $kandydaci[] = (string) @gzuncompress($tekst);
                }

                $poz += 12 + $dlugosc;
            }
        }

        foreach ($kandydaci as $tekst) {
            if (preg_match('/<x:xmpmeta\b.*?<\/x:xmpmeta>/s', $tekst, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }

    /** JPEG z GPS-em w EXIF-ie i pakietem XMP z fixture'u za nim. */
    private function jpegZGpsWObuMiejscach(): string
    {
        $jpeg = $this->jpegZGps();
        $xmp = (string) $this->xmpZPliku($this->fixture('xmp-gps.jpg'));
        $dane = "http://ns.adobe.com/xap/1.0/\x00".$xmp;
        $app1 = "\xFF\xE1".pack('n', strlen($dane) + 2).$dane;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }
}
