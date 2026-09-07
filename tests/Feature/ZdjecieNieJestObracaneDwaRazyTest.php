<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zdjęcie z telefonu nie może zostać obrócone dwa razy (audyt zewnętrzny
 * T11).
 *
 * NA CZYM POLEGA RYZYKO
 * Potok robi dwie rzeczy, każda z osobna poprawna:
 *
 *   1. `StoreUploadedImage` czyta `exif_orientation` PHP-owym `exif` w
 *      chwili wgrania — bo zadanie w tle dostaje same bajty ze storage,
 *      a sterownik GD nie czyta EXIF-u;
 *   2. `ProcessUploadedImage` woła `applyOrientation()` z tą wartością.
 *
 * Problem: `ImageManager::gd()` bierze domyślną konfigurację Intervention,
 * a w niej `Config::$autoOrientation = true` (`vendor/intervention/image/
 * src/Config.php:17`), i dekoder GD ten przełącznik honoruje
 * (`Drivers/Gd/Decoders/BinaryImageDecoder.php:62`). Jeśli więc dekoder
 * sam obróci obraz przy wczytaniu, nasz `applyOrientation()` obraca go
 * DRUGI RAZ.
 *
 * DLACZEGO TO MIERZYMY, A NIE WYWNIOSKUJEMY
 * Bo z samego czytania kodu nie wynika odpowiedź: GD nie ma dostępu do
 * EXIF-u, więc pytanie brzmi, czy dekoder Intervention czyta go sam obok
 * GD. To rozstrzyga wyłącznie uruchomienie na prawdziwym pliku.
 *
 * JAK ODCZYTAĆ WYNIK
 * Plik testowy ma 100×50 px zapisanych bajtów i EXIF `Orientation = 6`
 * (obróć o 90° w prawo przy wyświetlaniu). Poprawny wynik po JEDNYM
 * obrocie to wariant WYŻSZY niż szerszy. Po dwóch obrotach wróciłby do
 * orientacji poziomej — i to jest sygnał podwójnego obrotu, widoczny
 * w samych wymiarach, bez oglądania pikseli.
 *
 * Dla grupy 50+ obrócone zdjęcie nie jest drobiazgiem: osoba, która
 * wrzuci danie do góry nogami, nie zgłosi błędu — po prostu przestanie
 * wrzucać zdjęcia.
 */
class ZdjecieNieJestObracaneDwaRazyTest extends TestCase
{
    use RefreshDatabase;

    /** Buduje JPEG 100×50 z EXIF `Orientation = 6`. */
    private function jpegZOrientacja(int $orientacja): string
    {
        $tiff = 'II'."\x2A\x00".pack('V', 8)
            .pack('v', 1)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientacja)."\x00\x00"
            .pack('V', 0);

        $app1 = "Exif\x00\x00".$tiff;

        $obraz = imagecreatetruecolor(100, 50);
        // Dwa pasy koloru, żeby obraz nie był jednolity — jednolity
        // przechodziłby każdy pomiar niezależnie od obrotu.
        imagefilledrectangle($obraz, 0, 0, 99, 24, imagecolorallocate($obraz, 200, 30, 30));
        imagefilledrectangle($obraz, 0, 25, 99, 49, imagecolorallocate($obraz, 30, 30, 200));

        ob_start();
        imagejpeg($obraz, null, 95);
        $jpeg = (string) ob_get_clean();

        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function wgrajIPrzetworz(int $orientacja): Media
    {
        Storage::fake('testowy');
        config([
            'kuking.media.disk' => 'testowy',
            'kuking.media.public_disk' => 'testowy',
        ]);

        $sciezka = tempnam(sys_get_temp_dir(), 'orient').'.jpg';
        file_put_contents($sciezka, $this->jpegZOrientacja($orientacja));

        $media = app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true),
        );

        (new ProcessUploadedImage((string) $media->getKey()))->handle();

        @unlink($sciezka);

        return $media->fresh();
    }

    /**
     * KONTROLA. Plik testowy naprawdę niesie orientację 6 — bez tego cały
     * pomiar dotyczyłby zdjęcia bez EXIF-u i przechodził na próżno.
     */
    public function test_plik_testowy_naprawde_ma_orientacje(): void
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'orient').'.jpg';
        file_put_contents($sciezka, $this->jpegZOrientacja(6));

        $exif = @exif_read_data($sciezka);
        $rozmiar = @getimagesize($sciezka);
        @unlink($sciezka);

        $this->assertSame(6, $exif['Orientation'] ?? null, 'Plik testowy nie ma orientacji 6.');
        $this->assertSame([100, 50], [$rozmiar[0], $rozmiar[1]], 'Plik testowy nie ma 100×50 zapisanych pikseli.');
    }

    /**
     * WŁAŚCIWY POMIAR. Po przetworzeniu wariant musi być WYŻSZY niż
     * szerszy — jeden obrót o 90°, nie dwa.
     */
    public function test_zdjecie_z_orientacja_6_konczy_pionowo(): void
    {
        $media = $this->wgrajIPrzetworz(6);

        [$szerokosc, $wysokosc] = $this->wymiaryWariantu($media);

        $this->assertGreaterThan(
            $szerokosc,
            $wysokosc,
            "Wariant ma {$szerokosc}×{$wysokosc} px, czyli został obrócony PARZYSTĄ liczbę razy. "
            .'Przy orientacji 6 poprawny wynik jest pionowy — poziomy oznacza, że dekoder '
            .'Intervention obrócił obraz sam, a `applyOrientation()` obrócił go drugi raz.',
        );
    }

    /**
     * KONTROLA drugiej strony: zdjęcie BEZ orientacji (albo z `1`) zostaje
     * poziome. Bez tego testu naprawa mogłaby obracać wszystko i pierwszy
     * pomiar nadal by przechodził.
     */
    public function test_zdjecie_bez_obrotu_zostaje_poziome(): void
    {
        $media = $this->wgrajIPrzetworz(1);

        [$szerokosc, $wysokosc] = $this->wymiaryWariantu($media);

        $this->assertGreaterThan(
            $wysokosc,
            $szerokosc,
            "Zdjęcie bez obrotu wyszło {$szerokosc}×{$wysokosc} px — zostało obrócone, choć nie miało być.",
        );
    }

    /**
     * Wymiary wariantu `feed` czytane Z SAMYCH BAJTÓW w storage, nie
     * z `metadata.variants`.
     *
     * Różnica jest istotna: zapisane `width`/`height` biorą się z tego, co
     * Intervention MYŚLI, że zrobił. Gdyby obrót policzył się dwa razy,
     * a metadane opisywały stan po jednym, test na metadanych byłby zielony
     * przy zdjęciu obróconym na ekranie. Bajty nie mają takiej możliwości.
     *
     * @return array{0: int, 1: int}
     */
    private function wymiaryWariantu(Media $media): array
    {
        // Rozbite na kroki z jawnymi asercjami typu, bo `metadata` to JSONB
        // rzutowany na tablicę — dla analizy statycznej tablica o nieznanej
        // zawartości. Ten sam powód, dla którego `Media::warianty()` robi
        // `is_array()` zamiast wejść wprost w klucz.
        $metadata = $media->metadata;
        $this->assertIsArray($metadata, 'Zdjęcie nie ma metadanych.');

        $warianty = $metadata['variants'] ?? null;
        $this->assertIsArray($warianty, 'Metadane nie mają wariantów.');

        $wariant = $warianty['feed'] ?? null;
        $this->assertIsArray($wariant, 'Wariant `feed` nie powstał — nie ma czego mierzyć.');

        $klucz = $wariant['key'] ?? null;
        $this->assertIsString($klucz, 'Wariant nie ma klucza w storage.');

        $bajty = Storage::disk('testowy')->get($klucz);
        $rozmiar = @getimagesizefromstring((string) $bajty);

        $this->assertIsArray($rozmiar, 'Wariant nie jest czytelnym obrazem.');

        return [(int) $rozmiar[0], (int) $rozmiar[1]];
    }
}
