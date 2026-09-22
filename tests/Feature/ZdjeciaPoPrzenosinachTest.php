<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wykrywanie wierszy, które JUŻ zostały przestawione mimo braku pliku (#1031).
 *
 * Poprawka w `kuking:przenies-zdjecia` zamyka drogę na przyszłość, ale nie
 * cofa wierszy, które zdążyła przestawić stara wersja komendy. Bez osobnego
 * narzędzia takiego wiersza nie znajdzie już nic: wypadł z kolejki migracji,
 * a widok po prostu pokazuje brak zdjęcia bez żadnego śladu w logach.
 *
 * Narzędzie jest ŚWIADOMIE tylko do odczytu — patrz test na końcu pliku.
 */
class ZdjeciaPoPrzenosinachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_legacy');
        Storage::fake('nowe_oryginaly');
        Storage::fake('nowe_publiczne');

        config([
            'kuking.media.disk' => 'nowe_oryginaly',
            'kuking.media.public_disk' => 'nowe_publiczne',
            'filesystems.disks.r2_legacy.bucket' => 'kuking-media-stary',
        ]);
    }

    /** Wiersz wskazujący na nowe buckety — taki, jaki zostawiały przenosiny. */
    private function przestawioneZdjecie(): Media
    {
        return Media::factory()->create([
            'disk' => 'nowe_oryginaly',
            'variants_disk' => 'nowe_publiczne',
            'status' => Media::STATUS_READY,
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik_thumb.webp'],
                'feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp'],
            ]],
        ]);
    }

    private function polozPliki(Media $media, string $dyskOryginalu, string $dyskWariantow): void
    {
        Storage::disk($dyskOryginalu)->put((string) $media->object_key, 'oryginal z exifem');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk($dyskWariantow)->put($wariant['key'], 'wariant');
        }
    }

    public function test_komplet_plikow_na_miejscu_daje_czysty_raport(): void
    {
        $media = $this->przestawioneZdjecie();
        $this->polozPliki($media, 'nowe_oryginaly', 'nowe_publiczne');

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')
            ->expectsOutputToContain('UTRACONE (pliku nie ma nigdzie): 0')
            ->assertSuccessful();
    }

    public function test_wiersz_przestawiony_mimo_braku_pliku_jest_zglaszany_jako_utracony(): void
    {
        // Dokładnie to, co zostawiała po sobie stara komenda: `disk` wskazuje
        // nowy bucket, a pliku nie ma ani tam, ani w starym.
        $media = $this->przestawioneZdjecie();

        // Dwa oczekiwania muszą trafić w DWIE RÓŻNE linie: Mockery przypisuje
        // jedno wywołanie `doWrite` do jednej pasującej oczekiwanej wartości,
        // więc dwa podciągi z tej samej linii nie zostałyby oba odhaczone.
        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')
            ->expectsOutputToContain('UTRACONE oryginał: '.$media->object_key)
            ->expectsOutputToContain('UTRACONE (pliku nie ma nigdzie): 1 wiersz')
            ->assertFailed();
    }

    public function test_plik_lezacy_jeszcze_w_starym_buckecie_jest_do_odzyskania(): void
    {
        // Ten sam objaw, ale bajty wciąż są. Rozróżnienie jest tu całą
        // wartością: jedno da się naprawić, drugiego nie.
        $media = $this->przestawioneZdjecie();
        $this->polozPliki($media, 'r2_legacy', 'r2_legacy');

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')
            ->expectsOutputToContain('DO ODZYSKANIA')
            ->expectsOutputToContain('UTRACONE (pliku nie ma nigdzie): 0')
            ->assertFailed();
    }

    public function test_brak_tylko_jednego_wariantu_tez_jest_widoczny(): void
    {
        $media = $this->przestawioneZdjecie();
        $this->polozPliki($media, 'nowe_oryginaly', 'nowe_publiczne');

        Storage::disk('nowe_publiczne')->delete($media->metadata['variants']['feed']['key']);

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')
            ->expectsOutputToContain('wariant feed')
            ->assertFailed();
    }

    public function test_zdjecia_niegotowe_nie_zasmiecaja_raportu(): void
    {
        // `pending` jeszcze nie ma kompletu plików, `deleted` już go nie ma.
        // Brak pliku znaczy tam co innego niż utratę.
        Media::factory()->create([
            'disk' => 'nowe_oryginaly',
            'variants_disk' => 'nowe_publiczne',
            'status' => Media::STATUS_PENDING,
            'object_key' => 'incoming/basia/2026/09/w-trakcie.jpg',
            'metadata' => ['variants' => []],
        ]);

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')->assertSuccessful();

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach', ['--wszystkie-statusy' => true])
            ->assertFailed();
    }

    public function test_narzedzie_niczego_nie_kasuje_i_niczego_nie_przestawia(): void
    {
        // Przy zerowej liczbie kopii zapasowych bazy produkcyjnej automatyczne
        // „sprzątanie" byłoby drugą, świadomą utratą. Narzędzie ma raportować
        // i nic więcej — decyzja należy do właściciela danych.
        $media = $this->przestawioneZdjecie();
        $this->polozPliki($media, 'r2_legacy', 'r2_legacy');

        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')->assertFailed();

        $media->refresh();
        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variants_disk);
        $this->assertDatabaseCount('media', 1);

        Storage::disk('r2_legacy')->assertExists((string) $media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->assertExists($wariant['key']);
        }
    }
}
