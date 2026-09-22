<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\UsunGps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\TestCase;

/**
 * Oryginał zdjęcia traci współrzędne GPS, a resztę metadanych zachowuje
 * (D-023).
 *
 * DLACZEGO PIERWSZY TEST SPRAWDZA SAM PLIK TESTOWY
 * Bo bez tego cała reszta mogłaby przechodzić na próżno. Gdyby ręcznie
 * zbudowany EXIF był niepoprawny, `exif_read_data` nie zobaczyłby GPS-u ani
 * PRZED, ani PO — i test „GPS zniknął" byłby zielony, nie sprawdzając
 * niczego. `test_plik_testowy_naprawde_ma_gps` jest więc oracle'em dla
 * wszystkich pozostałych przypadków w tej klasie.
 */
class OryginalTraciWspolrzedneGpsTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;

    public function test_plik_testowy_naprawde_ma_gps(): void
    {
        $exif = $this->exif($this->jpegZGps());

        $this->assertArrayHasKey('GPSLatitude', $exif, 'Plik testowy nie ma GPS-u — reszta testów sprawdzałaby nic.');
        $this->assertArrayHasKey('GPSLongitude', $exif);
        $this->assertSame('TestPhone', $exif['Make'] ?? null);
    }

    public function test_wspolrzedne_znikaja(): void
    {
        $exif = $this->exif(UsunGps::zBajtow($this->jpegZGps()));

        $this->assertArrayNotHasKey('GPSLatitude', $exif, 'Współrzędne szerokości zostały w oryginale.');
        $this->assertArrayNotHasKey('GPSLongitude', $exif, 'Współrzędne długości zostały w oryginale.');
    }

    public function test_reszta_metadanych_zostaje(): void
    {
        $exif = $this->exif(UsunGps::zBajtow($this->jpegZGps()));

        $this->assertSame('TestPhone', $exif['Make'] ?? null, 'Producent aparatu zniknął razem z GPS-em.');
        $this->assertSame('2026:09:07 12:00:00', $exif['DateTime'] ?? null, 'Data zdjęcia zniknęła razem z GPS-em.');
    }

    /**
     * To jest ta część, której NIE załatwia samo ustawienie liczby wpisów
     * GPS na zero: bajty ze współrzędnymi zostałyby wtedy w pliku jako
     * nieodwoływany śmieć — niewidoczny dla czytnika EXIF, ale czytelny dla
     * każdego, kto zajrzy do pliku.
     */
    public function test_bajty_wspolrzednych_nie_zostaja_w_pliku(): void
    {
        $przed = $this->jpegZGps();
        $po = UsunGps::zBajtow($przed);

        // 5600/100 sekundy z szerokości — sekwencja, która w pliku
        // z GPS-em występuje, a w wyczyszczonym nie może.
        $szukane = pack('VV', 5600, 100);

        $this->assertStringContainsString($szukane, $przed, 'Plik testowy nie zawiera oczekiwanych bajtów — test nie mierzy tego, co myśli.');
        $this->assertStringNotContainsString($szukane, $po, 'Surowe bajty współrzędnych zostały w pliku, choć czytnik EXIF ich już nie widzi.');
    }

    public function test_zdjecie_zostaje_poprawnym_jpegiem_o_tej_samej_dlugosci(): void
    {
        $przed = $this->jpegZGps();
        $po = UsunGps::zBajtow($przed);

        $this->assertSame(strlen($przed), strlen($po), 'Plik zmienił długość — a wolno nam tylko zerować bajty w miejscu.');

        $sciezka = tempnam(sys_get_temp_dir(), 'gps').'.jpg';
        file_put_contents($sciezka, $po);
        $rozmiar = @getimagesize($sciezka);
        @unlink($sciezka);

        $this->assertIsArray($rozmiar, 'Po wyczyszczeniu GPS-u plik przestał być czytelnym obrazem.');
        $this->assertSame(8, $rozmiar[0]);
        $this->assertSame(8, $rozmiar[1]);
    }

    /**
     * Zdecydowana większość wgrań nie ma GPS-u wcale. Dla nich oryginał ma
     * zostać wierną kopią co do bajtu — inaczej „oryginał" przestaje być
     * oryginałem.
     */
    public function test_plik_bez_exifu_wraca_bez_zmiany(): void
    {
        $obraz = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($obraz, null, 90);
        $goly = (string) ob_get_clean();

        $this->assertSame($goly, UsunGps::zBajtow($goly), 'Plik bez EXIF-u został zmieniony, choć nie było w nim czego usuwać.');
    }

    public function test_plik_z_exifem_ale_bez_gps_wraca_bez_zmiany(): void
    {
        // Ten sam plik po wyczyszczeniu nie ma już GPS-u, więc drugie
        // przejście nie ma nic do roboty i musi być bezczynne.
        $raz = UsunGps::zBajtow($this->jpegZGps());

        $this->assertSame($raz, UsunGps::zBajtow($raz), 'Drugie przejście zmieniło plik, w którym nie ma już GPS-u.');
    }

    public function test_cos_co_nie_jest_obrazem_nie_wywala_sie(): void
    {
        $this->assertSame('', UsunGps::zBajtow(''));
        $this->assertSame('nie obraz', UsunGps::zBajtow('nie obraz'));

        // Uciety nagłówek EXIF: funkcja ma wyjść bez zmiany, nie rzucić.
        $ucięty = "\xFF\xD8\xFF\xE1\x00\x08Exif\x00\x00II";
        $this->assertSame($ucięty, UsunGps::zBajtow($ucięty));
    }

    /**
     * NAJWAŻNIEJSZY TEST W TEJ KLASIE. Poprzednie sprawdzają sam parser —
     * ten sprawdza, że jest on faktycznie wpięty w ścieżkę, którą idzie
     * prawdziwe wgranie. Bez niego `UsunGps` mógłby być doskonały i nigdy
     * nie wołany, a wszystkie pozostałe testy i tak byłyby zielone.
     */
    public function test_wgrane_zdjecie_lezy_w_buckecie_bez_wspolrzednych(): void
    {
        Storage::fake('testowy');
        config(['kuking.media.disk' => 'testowy']);

        $sciezka = tempnam(sys_get_temp_dir(), 'wgranie').'.jpg';
        file_put_contents($sciezka, $this->jpegZGps());

        $media = app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true),
            altText: 'Rosol',
        );

        $wBuckecie = Storage::disk('testowy')->get($media->object_key);

        $this->assertNotNull($wBuckecie, 'Oryginał nie trafił do bucketu.');

        $exif = $this->exif($wBuckecie);

        $this->assertArrayNotHasKey('GPSLatitude', $exif, 'Zdjęcie leży w buckecie ze współrzędnymi.');
        $this->assertSame('TestPhone', $exif['Make'] ?? null, 'Producent aparatu zniknął z zapisanego oryginału.');

        $this->assertStringNotContainsString(
            pack('VV', 5600, 100),
            $wBuckecie,
            'Surowe bajty współrzędnych leżą w buckecie.',
        );

        // Suma kontrolna ma opisywać to, co NAPRAWDĘ leży w buckecie.
        $this->assertSame(
            hash('sha256', $wBuckecie),
            $media->checksum_sha256,
            'Suma kontrolna opisuje plik przed zdjęciem GPS-u, czyli plik, którego nigdzie nie ma.',
        );

        @unlink($sciezka);
    }
}
