<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Warianty gotowych zdjęć od nowa, bez tinkera (issue #2223, D-333).
 *
 * Runbook `KOPIE_I_ODTWORZENIE.md` §3 (c1) kazał dotąd zlecić w tinkerze
 * zwykłe `ProcessUploadedImage` dla każdego zdjęcia. To NIE DZIAŁAŁO:
 * zadanie odpuszcza `ready` jako spóźnioną kopię zlecenia z uploadu.
 * Pierwszy test niżej pokazuje to wprost i jest kontrolą dodatnią dla
 * drugiego — bez niego „warianty wróciły" przechodziłoby także przy
 * zadaniu, które robi warianty zawsze.
 */
class PrzetworzZdjeciaPonownieTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'oryginal2223';

    private const WARIANTY = 'wariant2223';

    public function test_zwykle_zadanie_nie_odtwarza_wariantow_gotowego_zdjecia(): void
    {
        $warianty = $this->dyski();
        $media = $this->gotoweZdjecieBezWariantow();

        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertSame([], $warianty->allFiles(), 'Zwykłe zadanie zrobiło warianty gotowego zdjęcia — ten test przestał opisywać, dlaczego potrzebny jest tryb odtworzenia.');
    }

    public function test_odtworzenie_robi_warianty_z_oryginalu_a_zdjecie_zostaje_gotowe(): void
    {
        $warianty = $this->dyski();
        $media = $this->gotoweZdjecieBezWariantow();

        ProcessUploadedImage::odtworzWarianty($media->getKey())->handle();

        $media->refresh();
        $this->assertSame(Media::STATUS_READY, $media->status);

        foreach (array_keys(config('kuking.media.variants')) as $nazwa) {
            $klucz = Media::kluczPublicznegoWariantu($media->object_key, (string) $nazwa);
            $this->assertTrue($warianty->exists($klucz), "Nie ma wariantu {$nazwa} po odtworzeniu.");
            $this->assertSame($klucz, $media->metadata['variants'][$nazwa]['key'] ?? null);
        }

        $this->assertArrayNotHasKey(Media::METADANE_WARIANTY_W_TRAKCIE, $media->metadata, 'Po sukcesie została lista kluczy „w trakcie”.');
    }

    public function test_nieudane_odtworzenie_nie_odbiera_gotowego_zdjecia(): void
    {
        $this->dyski();
        $media = $this->gotoweZdjecieBezWariantow();
        Storage::disk(self::ORYGINALY)->delete($media->object_key);

        try {
            ProcessUploadedImage::odtworzWarianty($media->getKey())->handle();
            $this->fail('Zadanie bez oryginału miało zgłosić błąd, żeby kolejka ponowiła próbę.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Media::STATUS_READY, $media->refresh()->status, 'Nieudane odtworzenie zamieniło gotowe zdjęcie w `rejected`.');

        // Kontrola dodatnia: ten sam brak oryginału przy zwykłym zadaniu
        // (bez kolejki = ostatnia próba) daje `rejected`. Inaczej asercja
        // wyżej przechodziłaby także wtedy, gdy zadanie nie doszło do błędu.
        $swieze = Media::query()->findOrFail($media->getKey());
        $swieze->forceFill(['status' => Media::STATUS_PENDING])->save();

        try {
            (new ProcessUploadedImage($media->getKey()))->handle();
        } catch (RuntimeException) {
        }

        $this->assertSame(Media::STATUS_REJECTED, $media->refresh()->status);
    }

    public function test_odtworzenie_nie_rusza_zdjecia_ktore_nie_jest_gotowe(): void
    {
        $warianty = $this->dyski();
        $media = $this->gotoweZdjecieBezWariantow();
        $media->forceFill(['status' => Media::STATUS_PENDING])->save();

        ProcessUploadedImage::odtworzWarianty($media->getKey())->handle();

        $this->assertSame(Media::STATUS_PENDING, $media->refresh()->status);
        $this->assertSame([], $warianty->allFiles());
    }

    public function test_bez_wykonaj_komenda_tylko_liczy(): void
    {
        Queue::fake();
        Media::factory()->count(2)->create();
        Media::factory()->pending()->create();

        $this->artisan('kuking:przetworz-zdjecia-ponownie')
            ->expectsOutputToContain('W zakresie jest 2 gotowe zdjęcia. Nic nie zlecono.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_z_wykonaj_zleca_odtworzenie_tylko_gotowych_zdjec_z_zakresu(): void
    {
        Queue::fake();
        $naDysku = Media::factory()->create(['variants_disk' => 'r2_publiczne']);
        $bezOsobnegoDysku = Media::factory()->create(['disk' => 'r2_publiczne', 'variants_disk' => null]);
        Media::factory()->create(['variants_disk' => 'inny']);
        Media::factory()->pending()->create(['variants_disk' => 'r2_publiczne']);

        $this->artisan('kuking:przetworz-zdjecia-ponownie', ['--dysk' => 'r2_publiczne', '--wykonaj' => true])
            ->expectsOutputToContain('Zlecono 2 zadania')
            ->assertSuccessful();

        Queue::assertPushed(ProcessUploadedImage::class, 2);
        Queue::assertPushed(ProcessUploadedImage::class, fn (ProcessUploadedImage $zadanie) => $zadanie->odtworzenie
            && in_array($zadanie->mediaId, [$naDysku->getKey(), $bezOsobnegoDysku->getKey()], true));
        Queue::assertPushedOn('media', ProcessUploadedImage::class);
    }

    public function test_jedno_zdjecie_po_uuid(): void
    {
        Queue::fake();
        $jedno = Media::factory()->create();
        Media::factory()->create();

        $this->artisan('kuking:przetworz-zdjecia-ponownie', ['--media' => $jedno->getKey(), '--wykonaj' => true])
            ->expectsOutputToContain('Zlecono 1 zadanie')
            ->assertSuccessful();

        Queue::assertPushed(ProcessUploadedImage::class, 1);
        Queue::assertPushed(ProcessUploadedImage::class, fn (ProcessUploadedImage $zadanie) => $zadanie->mediaId === $jedno->getKey());
    }

    public function test_zly_uuid_i_brak_zdjecia_to_blad_z_podpowiedzia(): void
    {
        Queue::fake();

        $this->artisan('kuking:przetworz-zdjecia-ponownie', ['--media' => 'nie-uuid'])
            ->expectsOutputToContain('Podaj UUID')
            ->assertFailed();

        $this->artisan('kuking:przetworz-zdjecia-ponownie', ['--media' => '9d1c2f4e-0000-4000-8000-000000000000', '--wykonaj' => true])
            ->expectsOutputToContain('Nie ma ani jednego gotowego zdjęcia')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    private function dyski(): Filesystem
    {
        Storage::fake(self::ORYGINALY);

        return Storage::fake(self::WARIANTY);
    }

    /**
     * Stan ze scenariusza (c1): wiersz jest `ready` i zna swoje warianty,
     * ale plików wariantów w buckecie już nie ma. Oryginał jest cały.
     */
    private function gotoweZdjecieBezWariantow(): Media
    {
        $klucz = 'incoming/odtworzenie-2223.jpg';
        Storage::disk(self::ORYGINALY)->put($klucz, $this->obrazek());

        $warianty = [];
        foreach (array_keys(config('kuking.media.variants')) as $nazwa) {
            $warianty[$nazwa] = ['key' => Media::kluczPublicznegoWariantu($klucz, (string) $nazwa), 'width' => 1, 'height' => 1, 'bytes' => 1];
        }

        return Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => self::ORYGINALY,
            'variants_disk' => self::WARIANTY,
            'object_key' => $klucz,
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }

    private function obrazek(): string
    {
        $image = imagecreatetruecolor(1400, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 30, 10));

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
