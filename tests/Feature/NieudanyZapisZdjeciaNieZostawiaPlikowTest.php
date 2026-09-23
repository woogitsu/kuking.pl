<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\PodgladOdRazu;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Błąd bazy po zapisie wgrania nie zostawia plików poza sprzątaniem (issue #962).
 *
 * `StoreUploadedImage` kładzie oryginał (i przy małym zdjęciu publiczny
 * podgląd) do storage PRZED `Media::create()`. Gdy tworzenie wiersza padało,
 * pliki zostawały bez wiersza — a wszystko, co w serwisie kasuje zdjęcia,
 * zaczyna od tabeli `media`, więc nie znalazłoby ich już nic: ani
 * sprzątanie osieroconych, ani wymazanie konta.
 *
 * Błąd wstrzykujemy obserwatorem `creating` — po prawdziwym zapisie do
 * `Storage::fake()`, dokładnie w miejscu, w którym pada zerwane połączenie
 * albo naruszone ograniczenie.
 */
class NieudanyZapisZdjeciaNieZostawiaPlikowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('oryginal962');
        Storage::fake('wariant962');
        config([
            'kuking.media.disk' => 'oryginal962',
            'kuking.media.public_disk' => 'wariant962',
        ]);
    }

    /**
     * KONTROLA DODATNIA: przy sukcesie oba pliki i wiersz ISTNIEJĄ. Bez tego
     * „nic nie zostało" niżej przechodziłoby też wtedy, gdy podgląd nie
     * powstaje wcale (np. zdjęcie ponad progiem).
     */
    public function test_udane_wgranie_zostawia_oryginal_podglad_i_wiersz(): void
    {
        $media = $this->wgraj();

        Storage::disk('oryginal962')->assertExists($media->object_key);
        Storage::disk('wariant962')->assertExists(Media::kluczPublicznegoWariantu($media->object_key, PodgladOdRazu::NAZWA));
        $this->assertSame(1, Media::query()->count());
    }

    public function test_blad_tworzenia_wiersza_kasuje_oryginal_i_podglad_i_oddaje_pierwotny_wyjatek(): void
    {
        $this->wierszNiePowstanie();

        try {
            $this->wgraj();
            $this->fail('Wgranie miało paść na tworzeniu wiersza.');
        } catch (RuntimeException $e) {
            $this->assertSame('Połączenie z bazą zerwane.', $e->getMessage(), 'Wywołujący nie dostał pierwotnego wyjątku.');
        }

        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('oryginal962')->allFiles(), 'Oryginał został w buckecie bez wiersza `media` — nic go już nie skasuje.');
        $this->assertSame([], Storage::disk('wariant962')->allFiles(), 'Publiczny podgląd został w buckecie bez wiersza `media`.');
    }

    /**
     * Kompensacja, która się nie udała, nie udaje sprzątnięcia: zostaje błąd
     * w dzienniku z dyskiem i kluczem, a wywołujący i tak dostaje PIERWOTNY
     * wyjątek, nie wyjątek z kasowania.
     */
    public function test_nieudana_kompensacja_zostawia_slad_z_kluczem_i_nie_podmienia_wyjatku(): void
    {
        $this->wierszNiePowstanie();

        $prawdziwy = Storage::disk('oryginal962');
        Storage::set('oryginal962', new class($prawdziwy)
        {
            public function __construct(private Filesystem $prawdziwy) {}

            public function delete($sciezki)
            {
                throw new RuntimeException('Bucket odmówił kasowania.');
            }

            public function __call(string $metoda, array $argumenty): mixed
            {
                return $this->prawdziwy->{$metoda}(...$argumenty);
            }
        });

        Log::spy();

        try {
            $this->wgraj();
            $this->fail('Wgranie miało paść na tworzeniu wiersza.');
        } catch (RuntimeException $e) {
            $this->assertSame('Połączenie z bazą zerwane.', $e->getMessage());
        }

        $klucz = $prawdziwy->allFiles()[0] ?? null;

        $this->assertNotNull($klucz, 'Oryginał miał zostać — kasowanie odmówiło.');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $wiadomosc, array $kontekst): bool => ($kontekst['dysk'] ?? null) === 'oryginal962'
                && ($kontekst['klucz'] ?? null) === $klucz)
            ->once();

        // Podgląd na drugim dysku i tak zniknął: porażka jednego pliku nie
        // pomija pozostałych.
        $this->assertSame([], Storage::disk('wariant962')->allFiles());
    }

    private function wierszNiePowstanie(): void
    {
        Media::creating(static function (): never {
            throw new RuntimeException('Połączenie z bazą zerwane.');
        });
    }

    private function wgraj(): Media
    {
        $obraz = imagecreatetruecolor(400, 300);
        imagefill($obraz, 0, 0, imagecolorallocate($obraz, 180, 90, 30));

        $sciezka = tempnam(sys_get_temp_dir(), 'wgranie962').'.jpg';
        imagejpeg($obraz, $sciezka, 90);
        imagedestroy($obraz);

        try {
            return app(StoreUploadedImage::class)->handle(
                owner: $this->user('kucharz962'),
                file: new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true),
            );
        } finally {
            @unlink($sciezka);
        }
    }
}
