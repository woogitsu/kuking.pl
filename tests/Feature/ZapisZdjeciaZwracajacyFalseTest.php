<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\PodgladOdRazu;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

/**
 * `false` z `put()` to nieudany zapis, nie sukces (issue #961).
 *
 * Dysk z `throw => false` (w repozytorium: `local` i `public`) przy awarii
 * NIE rzuca, tylko oddaje `false`. Potok zdjęć tego nie sprawdzał w trzech
 * miejscach: oryginał, podgląd od razu i warianty zadania w tle. Najgorszy
 * skutek: zdjęcie `ready` z adresami plików, których nie ma — trwale martwy
 * obrazek, bez ponowienia.
 *
 * Każda granica ma parę testów: `false` (nie ma fałszywego sukcesu) i
 * kontrolę dodatnią z `true` na TYM SAMYM opakowanym dysku (ścieżka sukcesu
 * zostaje, a opakowanie niczego samo nie psuje).
 */
class ZapisZdjeciaZwracajacyFalseTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'oryginal961';

    private const WARIANTY = 'wariant961';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.media.disk' => self::ORYGINALY,
            'kuking.media.public_disk' => self::WARIANTY,
        ]);
    }

    public function test_oryginal_z_false_nie_tworzy_wiersza_ani_zadania(): void
    {
        Queue::fake();
        $this->dyskZFalse(self::ORYGINALY, Storage::fake(self::ORYGINALY), odmowZapisu: true);
        $warianty = Storage::fake(self::WARIANTY);

        try {
            app(StoreUploadedImage::class)->handle($this->user(), UploadedFile::fake()->image('obiad.jpg', 800, 600));
            $this->fail('Upload z nieudanym zapisem oryginału udał sukces.');
        } catch (UnableToWriteFile) {
            // Oczekiwane: ten sam wyjątek co z dysku `throw => true`.
        }

        $this->assertSame(0, Media::query()->count(), 'Powstał wiersz `media` dla oryginału, którego nie ma.');
        Queue::assertNothingPushed();
        $this->assertSame([], $warianty->allFiles(), 'Podgląd powstał mimo nieudanego zapisu oryginału.');
    }

    public function test_kontrola_dodatnia_oryginal_z_true_przechodzi(): void
    {
        Queue::fake();
        $oryginaly = Storage::fake(self::ORYGINALY);
        $this->dyskZFalse(self::ORYGINALY, $oryginaly, odmowZapisu: false);
        Storage::fake(self::WARIANTY);

        $media = app(StoreUploadedImage::class)->handle($this->user(), UploadedFile::fake()->image('obiad.jpg', 800, 600));

        $this->assertSame(Media::STATUS_PENDING, $media->status);
        $this->assertTrue($oryginaly->exists($media->object_key));
        Queue::assertPushed(ProcessUploadedImage::class, 1);
    }

    public function test_podglad_z_false_nie_deklaruje_nieistniejacego_wariantu(): void
    {
        Queue::fake();
        $log = Log::spy();
        Storage::fake(self::ORYGINALY);
        $this->dyskZFalse(self::WARIANTY, Storage::fake(self::WARIANTY), odmowZapisu: true);

        $media = app(StoreUploadedImage::class)->handle($this->user(), UploadedFile::fake()->image('obiad.jpg', 800, 600));

        // Upload się udaje — podgląd jest udogodnieniem, nie warunkiem.
        $this->assertSame(Media::STATUS_PENDING, $media->status);
        $this->assertSame([], $media->metadata['variants'], 'W metadanych jest podgląd, którego pliku nie ma.');
        $this->assertNull($media->wariantDoSerwowania(PodgladOdRazu::NAZWA));
        Queue::assertPushed(ProcessUploadedImage::class, 1);

        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $wiadomosc): bool => str_contains($wiadomosc, 'podglądu od razu'))
            ->once();
    }

    public function test_kontrola_dodatnia_podglad_z_true_jest_w_metadanych(): void
    {
        Queue::fake();
        Storage::fake(self::ORYGINALY);
        $warianty = Storage::fake(self::WARIANTY);
        $this->dyskZFalse(self::WARIANTY, $warianty, odmowZapisu: false);

        $media = app(StoreUploadedImage::class)->handle($this->user(), UploadedFile::fake()->image('obiad.jpg', 800, 600));

        $podglad = $media->wariant(PodgladOdRazu::NAZWA);

        $this->assertIsArray($podglad);
        $this->assertTrue($warianty->exists($podglad['key'] ?? ''));
    }

    public function test_wariant_z_false_nie_konczy_sie_ready_i_zadanie_zostaje_do_ponowienia(): void
    {
        Storage::fake(self::ORYGINALY);
        $warianty = Storage::fake(self::WARIANTY);
        $media = $this->zdjecieWKolejce();
        $this->dyskZFalse(self::WARIANTY, $warianty, odmowZapisu: true);

        try {
            (new ProcessUploadedImage($media->getKey()))->handle();
            $this->fail('Zadanie z nieudanym zapisem wariantu nie rzuciło — nie będzie ponowienia ani `failed_jobs`.');
        } catch (UnableToWriteFile) {
            // Oczekiwane: wyjątek wychodzi z `handle()`, więc kolejka ponowi.
        }

        $media->refresh();

        $this->assertNotSame(Media::STATUS_READY, $media->status, 'Zdjęcie `ready` bez plików wariantów.');
        $this->assertSame(Media::STATUS_REJECTED, $media->status);
        $this->assertSame([], $media->metadata['variants'] ?? [], 'W `variants` jest wariant, którego pliku nie ma.');
    }

    public function test_kontrola_dodatnia_wariant_z_true_konczy_sie_ready(): void
    {
        Storage::fake(self::ORYGINALY);
        $warianty = Storage::fake(self::WARIANTY);
        $media = $this->zdjecieWKolejce();
        $this->dyskZFalse(self::WARIANTY, $warianty, odmowZapisu: false);

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertCount(count(config('kuking.media.variants')), $media->metadata['variants']);

        foreach ($media->metadata['variants'] as $wariant) {
            $this->assertTrue($warianty->exists($wariant['key']));
        }
    }

    /**
     * Opakowanie dysku, którego `put()` przy `$odmowZapisu` nie zapisuje
     * niczego i oddaje `false` — dokładnie jak dysk z `throw => false`
     * przy awarii magazynu. Reszta metod idzie do prawdziwego dysku.
     */
    private function dyskZFalse(string $nazwa, Filesystem $prawdziwy, bool $odmowZapisu): void
    {
        Storage::set($nazwa, new class($prawdziwy, $odmowZapisu)
        {
            public function __construct(private Filesystem $prawdziwy, private bool $odmowZapisu) {}

            public function put($sciezka, $zawartosc, $opcje = [])
            {
                if ($this->odmowZapisu) {
                    return false;
                }

                return $this->prawdziwy->put($sciezka, $zawartosc, $opcje);
            }

            public function __call(string $metoda, array $argumenty): mixed
            {
                return $this->prawdziwy->{$metoda}(...$argumenty);
            }
        });
    }

    private function zdjecieWKolejce(): Media
    {
        $klucz = 'incoming/zapis-961.jpg';
        Storage::disk(self::ORYGINALY)->put($klucz, UploadedFile::fake()->image('obiad.jpg', 800, 600)->get());

        return Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => self::ORYGINALY,
            'variants_disk' => self::WARIANTY,
            'object_key' => $klucz,
            'status' => Media::STATUS_PENDING,
            'metadata' => [],
        ]);
    }
}
