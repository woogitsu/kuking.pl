<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Domain\Users\Actions\EraseAccountData;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Zadanie przetwarzania nie odtwarza publicznych wariantów zdjęcia, które
 * odchodzi (issue #1003).
 *
 * `ProcessUploadedImage` odpuszczało wyłącznie `ready`. Status `deleted`
 * („kasowanie trwa", D-083) przestawiało z powrotem na `processing`, a pliki
 * wariantów kładło do publicznego bucketu także PO tym, jak kasowanie
 * skończyło i usunęło wiersz — bez żadnego klucza w bazie, po którym dałoby
 * się je jeszcze znaleźć.
 *
 * Przeploty wymuszamy dyskiem wariantów, który przed N-tym zapisem wykonuje
 * podaną akcję — dokładnie w oknie „zadanie odczytało oryginał i zdekodowało
 * obraz, a jeszcze nie opublikowało wariantów". Każdy przypadek sprawdza
 * KONTROLĘ DODATNIĄ: że zadanie naprawdę położyło plik, zanim zapytamy, czy
 * go posprzątało — inaczej „nic nie zostało" przechodziłoby też wtedy, gdy
 * zadanie nie zrobiło nic.
 */
class ZdjecieOdchodzaceNieWracaZWorkeraTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'oryginal1003';

    public const WARIANTY = 'wariant1003';

    public function test_zadanie_nie_rusza_zdjecia_ktore_juz_odchodzi(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();
        $media->forceFill(['status' => Media::STATUS_DELETED])->save();

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertSame(Media::STATUS_DELETED, $media->refresh()->status, 'Zadanie cofnęło `deleted` — znacznik „zdjęcie odchodzi" przestał być trwały.');
        $this->assertSame([], $prawdziwy->allFiles(), 'Zadanie położyło warianty zdjęcia, które odchodzi.');
    }

    /**
     * Najgorszy przeplot z issue: zadanie odczytało oryginał → kasowanie
     * przejmuje wiersz, kasuje znane pliki i usuwa wiersz → zadanie zapisuje
     * warianty.
     */
    public function test_kasowanie_konczy_sie_w_trakcie_zadania_i_nie_zostaje_zaden_wariant(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();

        $zapisane = $this->przedZapisem($prawdziwy, 1, function () use ($media): void {
            $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($media), 'Kasowanie nie skończyło się — przeplot nie zaszedł.');
        });

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertCount(3, $zapisane, 'Zadanie nie położyło wariantów — test nie sprawdza wtedy sprzątania.');
        $this->assertNull(Media::query()->find($media->getKey()), 'Wiersz `media` wrócił po kasowaniu.');
        $this->assertSame([], $prawdziwy->allFiles(), 'W publicznym buckecie zostały warianty bez wiersza `media` — nic ich już nie znajdzie.');
    }

    /**
     * Łagodniejszy przeplot: kasowanie przejęło wiersz (`deleted` zatwierdzone),
     * ale plików jeszcze nie skasowało. Zadanie nie może przestawić statusu
     * i musi zabrać swoje pliki; wiersz zostaje uchwytem do dokończenia.
     */
    public function test_przejecie_w_trakcie_zadania_zostaje_deleted_a_wlasne_pliki_znikaja(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();

        $zapisane = $this->przedZapisem($prawdziwy, 2, function () use ($media): void {
            Media::query()->whereKey($media->getKey())->update(['status' => Media::STATUS_DELETED]);
        });

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertCount(3, $zapisane);
        $this->assertSame(Media::STATUS_DELETED, $media->refresh()->status, 'Zadanie nadpisało `deleted` statusem `ready`.');
        $this->assertSame([], $media->metadata['variants'] ?? [], 'Zadanie opublikowało warianty zdjęcia, które odchodzi.');
        $this->assertSame([], $prawdziwy->allFiles(), 'Pliki położone przez zadanie zostały w publicznym buckecie.');
    }

    /** Błąd w trakcie przy zdjęciu, które odchodzi: bez `rejected` i bez sierot. */
    public function test_blad_zadania_przy_zdjeciu_ktore_odchodzi_nie_nadpisuje_deleted(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();

        $zapisane = $this->przedZapisem($prawdziwy, 2, function () use ($media): void {
            Media::query()->whereKey($media->getKey())->update(['status' => Media::STATUS_DELETED]);

            throw new RuntimeException('Bucket odmówił zapisu.');
        });

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertCount(1, $zapisane, 'Pierwszy wariant miał trafić do bucketu przed błędem.');
        $this->assertSame(Media::STATUS_DELETED, $media->refresh()->status);
        $this->assertSame([], $prawdziwy->allFiles());

        (new ProcessUploadedImage($media->getKey()))->failed(new RuntimeException('ostatnia próba'));

        $this->assertSame(Media::STATUS_DELETED, $media->refresh()->status, '`failed()` cofnęło `deleted` do `rejected`.');
    }

    public function test_wymazanie_konta_w_trakcie_zadania_nie_zostawia_wariantow(): void
    {
        Queue::fake();
        $prawdziwy = $this->dyski();
        $wlasciciel = $this->user('wymazywana1003');
        $media = $this->zdjecieWKolejce($wlasciciel);

        $zapisane = $this->przedZapisem($prawdziwy, 1, function () use ($wlasciciel): void {
            $wlasciciel->markForDeletion();
            $this->assertTrue((new EraseAccountData)->handle($wlasciciel->refresh()));
        });

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertCount(3, $zapisane);
        $this->assertNull(Media::query()->find($media->getKey()));
        $this->assertSame([], $prawdziwy->allFiles(), 'Po wymazaniu konta zostały publiczne warianty jego zdjęcia.');
        $this->assertSame([], Storage::disk(self::ORYGINALY)->allFiles(), 'Po wymazaniu konta został oryginał.');
    }

    /**
     * Druga kolejność przy wymazaniu: zadanie skończyło PO tym, jak wymazanie
     * wczytało modele zdjęć. Nieaktualny model nie zna wariantów — przejęcie
     * musi oddać świeży wiersz, inaczej `skasujPliki()` ich nie ruszy.
     */
    public function test_przejecie_do_wymazania_oddaje_swiezy_wiersz_z_wariantami(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();
        $nieaktualny = Media::query()->findOrFail($media->getKey());

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertCount(3, $prawdziwy->allFiles(), 'Zadanie nie opublikowało wariantów — kontrola dodatnia.');

        $kasuj = new KasujZdjecie;
        $przejete = $kasuj->przejmijDoWymazania($nieaktualny);

        $this->assertNotNull($przejete);
        $this->assertSame(Media::STATUS_DELETED, $przejete->status);
        $this->assertTrue($kasuj->skasujPliki($przejete));
        $this->assertSame([], $prawdziwy->allFiles(), 'Warianty z zadania, które skończyło po wczytaniu modelu, zostały w buckecie.');
    }

    public function test_zwykle_zdjecie_w_kolejce_nadal_staje_sie_gotowe(): void
    {
        $prawdziwy = $this->dyski();
        $media = $this->zdjecieWKolejce();

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertCount(3, $media->metadata['variants']);
        $this->assertCount(3, $prawdziwy->allFiles());
    }

    private function dyski(): Filesystem
    {
        Storage::fake(self::ORYGINALY);

        return Storage::fake(self::WARIANTY);
    }

    private function zdjecieWKolejce(?User $wlasciciel = null): Media
    {
        $klucz = 'incoming/odchodzi-1003.jpg';
        Storage::disk(self::ORYGINALY)->put($klucz, $this->obrazek());

        return Media::create([
            'owner_id' => ($wlasciciel ?? $this->user())->getKey(),
            'disk' => self::ORYGINALY,
            'variants_disk' => self::WARIANTY,
            'object_key' => $klucz,
            'status' => Media::STATUS_PENDING,
            'metadata' => [],
        ]);
    }

    /**
     * Dysk wariantów, który przed N-tym zapisem wykonuje `$akcja` raz.
     * Zwraca rejestr kluczy, które naprawdę poszły do bucketu.
     *
     * @return \ArrayObject<int, string>
     */
    private function przedZapisem(Filesystem $prawdziwy, int $ktory, Closure $akcja): \ArrayObject
    {
        $zapisane = new \ArrayObject;

        Storage::set(self::WARIANTY, new class($prawdziwy, $ktory, $akcja, $zapisane)
        {
            private int $proby = 0;

            public function __construct(
                private Filesystem $prawdziwy,
                private int $ktory,
                private Closure $akcja,
                private \ArrayObject $zapisane,
            ) {}

            public function put($sciezka, $zawartosc, $opcje = [])
            {
                if (++$this->proby === $this->ktory) {
                    // Kasowanie i wymazanie dostają PRAWDZIWY dysk: zadanie
                    // trzyma już ten obiekt i dalej pisze przez niego, a reszta
                    // aplikacji ma widzieć zwykły `Filesystem`.
                    Storage::set(ZdjecieOdchodzaceNieWracaZWorkeraTest::WARIANTY, $this->prawdziwy);
                    ($this->akcja)();
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

        ob_start();
        try {
            imagejpeg($image, null, 90);
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        return $bytes;
    }
}
