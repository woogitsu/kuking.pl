<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\PodgladOdRazu;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Karta odbioru #601 dla właściciela mówi prawdę o tym kodzie.
 *
 * Karta (`docs/infra/ODBIOR_WDROZEN_568_601_2026_09_20.md`, „Odbiór #601 na
 * produkcji") każe porównać wymiary trzech wariantów z KONKRETNYMI liczbami
 * i uruchomić w konsoli bazy gotowy SELECT. Jeśli któraś z tych rzeczy
 * rozjedzie się z kodem, właściciel dostanie „usterkę", której nie ma, albo
 * — gorzej — odbierze usterkę jako poprawny wynik. `JednoDekodowanieZdjeciaTest`
 * porównuje warianty z algorytmem bazowym, ale na obrazach do 1201 px, więc
 * wymiaru `large` 1600 z karty nie sprawdza nikt. Ten plik sprawdza:
 *
 * 1. zdjęcie 4:3 o dłuższym boku ≥ 1600 daje DOKŁADNIE liczby z karty,
 *    w poziomie (EXIF 1) i w pionie po obrocie (EXIF 6);
 * 2. SELECT z karty, wklejony dosłownie, działa na PostgreSQL po prawdziwym
 *    zadaniu i zwraca to, czego karta każe oczekiwać — z czwartym wariantem
 *    `podglad` obok, tak jak na produkcji.
 *
 * @bez-kontroli-dodatniej Czyta z dokumentu wyłącznie blok SQL, który wykonuje na PostgreSQL; asercje dotyczą wyniku zapytania i wymiarów wygenerowanych plików, nie treści dokumentu.
 */
class KartaOdbioruMedia601Test extends TestCase
{
    use RefreshDatabase;

    private const KARTA = 'docs/infra/ODBIOR_WDROZEN_568_601_2026_09_20.md';

    /**
     * @return array<string, array{0: int, 1: array<string, array{0: int, 1: int}>}>
     */
    public static function orientacje(): array
    {
        return [
            'poziome 4:3 (EXIF 1)' => [1, ['thumb' => [320, 240], 'feed' => [960, 720], 'large' => [1600, 1200]]],
            'pionowe 3:4 po obrocie (EXIF 6)' => [6, ['thumb' => [240, 320], 'feed' => [720, 960], 'large' => [1200, 1600]]],
        ];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $oczekiwane
     */
    #[Test]
    #[DataProvider('orientacje')]
    public function zadanie_daje_dokladnie_wymiary_z_karty_i_select_z_karty_je_odczytuje(int $orientacja, array $oczekiwane): void
    {
        Storage::fake('oryginal601');
        Storage::fake('wariant601');

        // Zapisany zawsze poziomo 2000×1500 — jak z aparatu. Pion bierze się
        // wyłącznie z EXIF, tak jak u właściciela fotografującego telefonem.
        Storage::disk('oryginal601')->put('incoming/karta.jpg', $this->jpeg(2000, 1500));

        $media = Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'oryginal601',
            'variants_disk' => 'wariant601',
            'object_key' => 'incoming/karta.jpg',
            'status' => Media::STATUS_PENDING,
            'metadata' => [
                'exif_orientation' => $orientacja,
                // Na produkcji podgląd bywa zrobiony synchronicznie przy
                // wgraniu; karta mówi, że czwarty wariant jest dozwolony.
                'variants' => [PodgladOdRazu::NAZWA => ['key' => 'media/karta-podglad.webp', 'width' => 640, 'height' => 480, 'bytes' => 1]],
            ],
        ]);

        (new ProcessUploadedImage($media->getKey()))->handle();

        // Punkt 2 karty: wymiary plików.
        $media->refresh();
        foreach ($oczekiwane as $wariant => [$szerokosc, $wysokosc]) {
            $zapisany = $media->wariant($wariant);
            $this->assertNotNull($zapisany, $wariant);
            [$w, $h] = getimagesizefromstring(Storage::disk('wariant601')->get($zapisany['key']));
            $this->assertSame([$szerokosc, $wysokosc], [$w, $h], "plik $wariant ma inne wymiary niż karta");
        }

        // Punkt 4 karty: SELECT wklejony dosłownie, tylko z UUID podstawionym.
        $wiersze = DB::select(str_replace('TU-UUID-WLASNEGO-ZDJECIA', $media->getKey(), $this->selectZKarty()));

        $this->assertCount(1, $wiersze, 'Karta obiecuje dokładnie 1 wiersz.');
        $wiersz = $wiersze[0];
        $this->assertSame(Media::STATUS_READY, $wiersz->status);
        $this->assertNotNull($wiersz->processed_at);
        $this->assertSame($orientacja !== 1 ? 'true' : 'false', $wiersz->orientation_applied);
        $this->assertFalse((bool) $wiersz->variants_in_progress);

        foreach ($oczekiwane as $wariant => [$szerokosc, $wysokosc]) {
            $this->assertSame((string) $szerokosc, $wiersz->{$wariant.'_width'}, "SELECT: $wariant szerokość");
            $this->assertSame((string) $wysokosc, $wiersz->{$wariant.'_height'}, "SELECT: $wariant wysokość");
        }

        // Czwarty wariant przeżył zadanie — karta każe go nie liczyć za błąd.
        $this->assertNotNull($media->wariant(PodgladOdRazu::NAZWA));
    }

    /** Pierwszy blok ```sql po nagłówku karty #601. */
    private function selectZKarty(): string
    {
        $dokument = (string) file_get_contents(base_path(self::KARTA));
        $karta = strpos($dokument, '## Odbiór #601 na produkcji');
        $this->assertNotFalse($karta, 'Zniknęła karta odbioru #601.');

        $poczatek = strpos($dokument, "```sql\n", $karta);
        $this->assertNotFalse($poczatek, 'Karta #601 nie ma już bloku SQL.');
        $poczatek += strlen("```sql\n");
        $koniec = strpos($dokument, '```', $poczatek);

        return trim(rtrim(substr($dokument, $poczatek, $koniec - $poczatek)), " \n;");
    }

    private function jpeg(int $szerokosc, int $wysokosc): string
    {
        $obraz = imagecreatetruecolor($szerokosc, $wysokosc);
        imagefilledrectangle($obraz, 0, 0, intdiv($szerokosc, 2), $wysokosc - 1, imagecolorallocate($obraz, 230, 30, 70));

        ob_start();
        try {
            imagejpeg($obraz, null, 90);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($obraz);
        }
    }
}
