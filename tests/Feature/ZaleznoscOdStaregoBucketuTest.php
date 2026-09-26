<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * `kuking:zaleznosc-od-starego-bucketu` — bramka przed jakimkolwiek ruszeniem
 * starego bucketu `r2_legacy`, który dla części zdjęć jest jedyną kopią.
 *
 * Kod wyjścia jest tu treścią: 0 znaczy WYŁĄCZNIE „żaden wiersz nie wskazuje
 * starego bucketu". Zależność z kompletem plików to nadal 1 — właśnie wtedy
 * stary bucket jest jedynym egzemplarzem i nie wolno go czyścić.
 */
class ZaleznoscOdStaregoBucketuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_legacy');
        Storage::fake('nowe_oryginaly');
        Storage::fake('nowe_publiczne');

        config(['filesystems.disks.r2_legacy.bucket' => 'kuking-media-stary']);
    }

    private function zdjecieWStarym(string $status = Media::STATUS_READY, bool $zPlikami = true): Media
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => 'r2_legacy',
            'status' => $status,
            'object_key' => 'incoming/basia/2026/09/sernik-'.uniqid().'.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik-'.uniqid().'_thumb.webp'],
                'feed' => ['key' => 'media/basia/2026/09/sernik-'.uniqid().'_feed.webp'],
            ]],
        ]);

        if ($zPlikami) {
            Storage::disk('r2_legacy')->put((string) $media->object_key, 'oryginal');

            foreach ($media->metadata['variants'] as $wariant) {
                Storage::disk('r2_legacy')->put($wariant['key'], 'wariant');
            }
        }

        return $media;
    }

    private function zdjecieWNowych(): Media
    {
        return Media::factory()->create([
            'disk' => 'nowe_oryginaly',
            'variants_disk' => 'nowe_publiczne',
            'status' => Media::STATUS_READY,
            'object_key' => 'incoming/marek/2026/09/pierogi.jpg',
            'metadata' => ['variants' => ['feed' => ['key' => 'media/marek/2026/09/pierogi_feed.webp']]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $parametry
     * @return array{0: int, 1: string}
     */
    private function bramka(array $parametry = []): array
    {
        $kod = Artisan::call('kuking:zaleznosc-od-starego-bucketu', $parametry);

        return [$kod, Artisan::output()];
    }

    public function test_bez_wierszy_w_starym_buckecie_bramka_jest_otwarta_ale_mowi_ze_to_warunek_konieczny(): void
    {
        $this->zdjecieWNowych();

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(0, $kod);
        $this->assertStringContainsString('Żaden wiersz media nie wskazuje starego bucketu.', $wyjscie);
        $this->assertStringContainsString('warunek KONIECZNY, nie wystarczający', $wyjscie);
    }

    public function test_zaleznosc_z_kompletem_plikow_zamyka_bramke(): void
    {
        // Sedno: wszystko na miejscu, a mimo to NIE wolno ruszać bucketu —
        // bo to jedyna kopia tych plików.
        $this->zdjecieWStarym();
        $this->zdjecieWStarym();
        $this->zdjecieWStarym(Media::STATUS_DELETED, zPlikami: false);
        $this->zdjecieWNowych();

        [$kod, $wyjscie] = $this->bramka(['--pliki' => true]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('STARY BUCKET JEST JEDYNĄ KOPIĄ 2 gotowych zdjęć', $wyjscie);
        $this->assertMatchesRegularExpression('/\|\s*RAZEM\s*\|\s*3\s*\|/', $wyjscie);
        $this->assertStringContainsString('Z brakującym plikiem (UTRACONE — nie ma ich nigdzie indziej): 0.', $wyjscie);
    }

    public function test_sam_wariant_w_starym_buckecie_tez_jest_zaleznoscia(): void
    {
        // Oryginał przeniesiony, warianty jeszcze nie — a `KasujZdjecie`
        // i widok czytają je właśnie ze starego bucketu.
        Media::factory()->create([
            'disk' => 'nowe_oryginaly',
            'variants_disk' => 'r2_legacy',
            'status' => Media::STATUS_READY,
            'object_key' => 'incoming/basia/2026/09/zupa.jpg',
            'metadata' => ['variants' => ['feed' => ['key' => 'media/basia/2026/09/zupa_feed.webp']]],
        ]);

        [$kod, $wyjscie] = $this->bramka(['--pliki' => true]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('JEDYNĄ KOPIĄ 1 gotowego zdjęcia', $wyjscie);
        $this->assertStringContainsString('brak wariant feed w starym buckecie', $wyjscie);
        $this->assertStringNotContainsString('brak oryginał', $wyjscie, 'Oryginał jest w nowym buckecie — nie wolno go szukać w starym.');
    }

    public function test_brakujacy_plik_jest_zglaszany_jako_utracony_bez_klucza_obiektu(): void
    {
        $media = $this->zdjecieWStarym();
        Storage::disk('r2_legacy')->delete((string) $media->object_key);

        [$kod, $wyjscie] = $this->bramka(['--pliki' => true]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('UTRACONE media '.$media->getKey().': brak oryginał w starym buckecie', $wyjscie);
        $this->assertStringContainsString('(UTRACONE — nie ma ich nigdzie indziej): 1.', $wyjscie);
        $this->assertStringNotContainsString((string) $media->object_key, $wyjscie, 'Raport wypisuje klucz obiektu.');
    }

    public function test_bez_bucketu_w_konfiguracji_mowi_ze_zdjecia_sie_nie_serwuja(): void
    {
        config(['filesystems.disks.r2_legacy.bucket' => '']);
        $this->zdjecieWStarym();

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('NIE SERWUJĄ SIĘ', $wyjscie);
        $this->assertStringContainsString('AWS_LEGACY_BUCKET', $wyjscie);
    }

    public function test_bez_opcji_pliki_nie_udaje_ze_pliki_sprawdzono(): void
    {
        $this->zdjecieWStarym();

        [$kod, $wyjscie] = $this->bramka();

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Pliki NIE były sprawdzane', $wyjscie);
    }

    public function test_blad_odczytu_nie_wypisuje_komunikatu_storage_i_wynik_jest_niepelny(): void
    {
        $media = $this->zdjecieWStarym();
        $dysk = Mockery::mock(FilesystemAdapter::class);
        $dysk->shouldReceive('exists')->andThrow(new RuntimeException(
            "Error executing HeadObject on https://konto.r2.example/x.jpg?X-Amz-Signature=deadbeef\r\nbasia@example.com",
        ));
        Storage::set('r2_legacy', $dysk);

        [$kod, $wyjscie] = $this->bramka(['--pliki' => true]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('BŁĄD ODCZYTU: media '.$media->getKey().' — '.RuntimeException::class.', odcisk ', $wyjscie);
        $this->assertStringContainsString('Wynik jest NIEPEŁNY', $wyjscie);

        foreach (['X-Amz-Signature', 'deadbeef', 'basia@example.com', 'konto.r2.example'] as $fraza) {
            $this->assertStringNotContainsString($fraza, $wyjscie);
        }
    }

    public function test_bramka_niczego_nie_kasuje_i_niczego_nie_przestawia(): void
    {
        $media = $this->zdjecieWStarym();

        $this->bramka(['--pliki' => true]);

        $this->assertSame('r2_legacy', $media->fresh()->disk);
        $this->assertSame('r2_legacy', $media->fresh()->variants_disk);
        Storage::disk('r2_legacy')->assertExists((string) $media->object_key);
        Storage::disk('nowe_oryginaly')->assertMissing((string) $media->object_key);
    }
}
