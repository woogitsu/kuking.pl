<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use ArrayObject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Zadanie przerwane w połowie nie zostawia pliku, którego nic nie skasuje (#601).
 *
 * SKĄD SIĘ WZIĄŁ PROBLEM
 * `ProcessUploadedImage` wysyła warianty do publicznego bucketu JEDEN PO
 * DRUGIM, a `metadata.variants` zapisuje dopiero po ostatnim z nich, razem
 * ze statusem `ready`. `KasujZdjecie` chodzi WYŁĄCZNIE po `metadata.variants`
 * — bo to jedyne miejsce, w którym wie, jak plik się nazywa.
 *
 * Między pierwszym `put()` a końcem zadania plik leży więc w publicznym
 * buckecie, a w bazie nie ma pod niego ŻADNEGO klucza. Zadanie, które
 * w tym oknie padnie na dobre, zostawia go tam NA ZAWSZE: nie kasuje go ani
 * usunięcie wpisu, ani decyzja moderacyjna, ani wymazanie konta (RODO), ani
 * `kuking:sprzataj-osierocone-zdjecia` — bo żadne z nich nie ma skąd wziąć
 * jego nazwy.
 *
 * To nie jest przypadek hipotetyczny. `$timeout` ubija proces SYGNAŁEM
 * w środku pętli — wtedy nie wykonuje się nawet `catch` w `handle()`
 * (uzasadnienie w `ZdjecieNieUtkniePrzetwarzaneTest`). Dekodowanie zdjęcia
 * 50 Mpx i trzy warianty w GD to dokładnie ten kawałek serwisu, który
 * potrafi się w limit czasu nie zmieścić.
 *
 * Dlatego klucze wariantów — policzalne z góry z `object_key` — lądują
 * w `metadata` JESZCZE PRZED pętlą, a `KasujZdjecie` je sprząta.
 *
 * Lista jest OSOBNA od `variants`, bo `Media::wariantDoSerwowania()` czyta
 * `variants` i pokazałaby zdjęcie w połowie przetwarzania pod nazwą
 * wariantu, którego plik może jeszcze nie istnieć. Ten test pilnuje obu
 * rzeczy naraz: że sierota znika i że zdjęcie w trakcie się nie pokazuje.
 */
class PrzerwanePrzetwarzanieNieZostawiaSierotyTest extends TestCase
{
    use RefreshDatabase;

    public function test_zadanie_przerwane_w_polowie_zostawia_klucze_ktore_kasowanie_znajdzie(): void
    {
        Storage::fake('oryginal601s');
        $prawdziwyDyskWariantow = Storage::fake('wariant601s');

        $media = $this->zdjecieWKolejce();
        $zapisane = $this->wariantyZPadajacymZapisem($prawdziwyDyskWariantow, padniecieNaZapisie: 2);

        try {
            (new ProcessUploadedImage($media->getKey()))->handle();
            $this->fail('Zadanie miało paść na drugim wariancie.');
        } catch (RuntimeException $e) {
            $this->assertSame('Bucket odmówił zapisu.', $e->getMessage());
        }

        Storage::set('wariant601s', $prawdziwyDyskWariantow);
        $media->refresh();

        // KONTROLA DODATNIA: bez niej cały test przechodzi również wtedy,
        // gdy zadanie padło PRZED pierwszym `put()` — czyli gdy sieroty
        // w ogóle nie było i nie ma czego sprzątać.
        $this->assertCount(1, $zapisane, 'Pierwszy wariant miał trafić do bucketu.');
        $this->assertTrue(
            $prawdziwyDyskWariantow->exists($zapisane[0]),
            'Plik pierwszego wariantu nie powstał — ten test nie sprawdza wtedy sieroty.',
        );

        $this->assertSame(Media::STATUS_REJECTED, $media->status);

        $klucze = $media->metadata[Media::METADANE_WARIANTY_W_TRAKCIE] ?? null;

        $this->assertIsArray($klucze, 'Klucze wariantów w trakcie nie zapisały się wcale.');
        $this->assertContains(
            $zapisane[0],
            $klucze,
            'Plik jest w publicznym buckecie, a w bazie nie ma pod niego klucza — nic go już nie skasuje.',
        );

        // Zdjęcie przerwane nie pokazuje się pod nazwą wariantu, którego
        // plik nie istnieje: lista „w trakcie" NIE jest listą do serwowania.
        $this->assertNull($media->wariantDoSerwowania('thumb'));
        $this->assertFalse($media->maWariantDoPokazania('thumb'));

        $this->assertTrue(
            (new KasujZdjecie)->skasujPliki($media),
            'Kasowanie zgłosiło porażkę, więc wiersz `media` zostanie jako uchwyt do ponowienia.',
        );

        $this->assertFalse(
            $prawdziwyDyskWariantow->exists($zapisane[0]),
            'Sierota została w publicznym buckecie mimo skasowania zdjęcia.',
        );
    }

    public function test_po_udanym_zadaniu_lista_w_trakcie_znika(): void
    {
        Storage::fake('oryginal601s');
        Storage::fake('wariant601s');

        $media = $this->zdjecieWKolejce();

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertArrayNotHasKey(
            Media::METADANE_WARIANTY_W_TRAKCIE,
            $media->metadata,
            'Po sukcesie każdy plik ma klucz w `variants`; druga lista tych samych plików tylko się rozjedzie.',
        );
        $this->assertCount(3, $media->metadata['variants']);
    }

    private function zdjecieWKolejce(): Media
    {
        $klucz = 'incoming/sierota-601.jpg';
        Storage::disk('oryginal601s')->put($klucz, $this->obrazek());

        return Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'oryginal601s',
            'variants_disk' => 'wariant601s',
            'object_key' => $klucz,
            'status' => Media::STATUS_PENDING,
            'metadata' => [],
        ]);
    }

    /**
     * Dysk wariantów, który przy N-tym zapisie zachowuje się jak bucket
     * odmawiający odpowiedzi — czyli tak, jak wygląda awaria R2 w środku
     * pętli. Zwraca rejestr kluczy, które naprawdę poszły do bucketu.
     */
    private function wariantyZPadajacymZapisem(Filesystem $prawdziwy, int $padniecieNaZapisie): ArrayObject
    {
        $zapisane = new ArrayObject;

        Storage::set('wariant601s', new class($prawdziwy, $padniecieNaZapisie, $zapisane)
        {
            public function __construct(
                private Filesystem $prawdziwy,
                private int $padniecieNaZapisie,
                private ArrayObject $zapisane,
            ) {}

            public function put($sciezka, $zawartosc, $opcje = [])
            {
                if ($this->zapisane->count() + 1 === $this->padniecieNaZapisie) {
                    throw new RuntimeException('Bucket odmówił zapisu.');
                }

                $this->zapisane[] = (string) $sciezka;

                return $this->prawdziwy->put($sciezka, $zawartosc, $opcje);
            }

            public function __call(string $metoda, array $argumenty): mixed
            {
                return $this->prawdziwy->{$metoda}(...$argumenty);
            }
        });

        return $zapisane;
    }

    private function obrazek(): string
    {
        $image = imagecreatetruecolor(1400, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 30, 90));
        imagefilledrectangle($image, 1, 1, 700, 898, imagecolorallocate($image, 230, 30, 70));

        ob_start();
        try {
            imagejpeg($image, null, 93);
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        return $bytes;
    }
}
