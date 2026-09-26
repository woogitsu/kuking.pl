<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\OdbiorZdjec;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * `kuking:odbior-zdjec` — odbiór prawdziwego zadania zdjęć po wdrożeniu (#601).
 *
 * Kontrola dodatnia idzie przez PRAWDZIWE `ProcessUploadedImage` na
 * wygenerowanym JPEG-u, a nie przez fabrykę: komenda ma rozpoznawać to, co
 * zadanie naprawdę zapisuje, a fabryka mogłaby się z nim rozjechać.
 */
class OdbiorZdjecTest extends TestCase
{
    use RefreshDatabase;

    private const OD = '2026-09-25T10:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('oryginal601');
        Storage::fake('wariant601');
        $this->travelTo('2026-09-25T10:05:00Z');
    }

    /** Zdjęcie wgrane i przetworzone przez prawdziwe zadanie w tle. */
    private function przetworzone(int $szer, int $wys, int $orientacja = 1): Media
    {
        $obraz = imagecreatetruecolor($szer, $wys);
        imagefill($obraz, 0, 0, imagecolorallocate($obraz, 200, 90, 40));
        ob_start();
        imagejpeg($obraz);
        $jpeg = (string) ob_get_clean();

        $klucz = 'incoming/odbior/'.uniqid().'.jpg';
        Storage::disk('oryginal601')->put($klucz, $jpeg);

        $media = Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'oryginal601',
            'variants_disk' => 'wariant601',
            'object_key' => $klucz,
            'width' => $szer,
            'height' => $wys,
            'status' => Media::STATUS_PENDING,
            'metadata' => ['exif_orientation' => $orientacja],
        ]);

        (new ProcessUploadedImage($media->getKey()))->handle();

        return $media->refresh();
    }

    public function test_kontrola_dodatnia_prawdziwe_zadanie_przechodzi_odbior_z_plikami(): void
    {
        $this->przetworzone(2400, 1600);
        $this->przetworzone(1800, 1200, 6);

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD, '--pliki' => true])
            ->expectsOutputToContain('Sprawdzone zdjęcia: 2')
            ->expectsOutputToContain('Odbiór przeszedł')
            ->assertSuccessful();
    }

    public function test_puste_okno_to_nic_nie_zmierzono_a_nie_sukces(): void
    {
        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('nic nie zmierzono')
            ->assertExitCode(OdbiorZdjec::NIC_NIE_ZMIERZONO);
    }

    public function test_zdjecie_sprzed_okna_nie_jest_liczone(): void
    {
        $this->przetworzone(800, 600);

        $this->artisan('kuking:odbior-zdjec', ['--od' => '2026-09-25T10:06:00Z'])
            ->assertExitCode(OdbiorZdjec::NIC_NIE_ZMIERZONO);
    }

    public function test_brak_wariantu_jest_porazka(): void
    {
        $media = $this->przetworzone(2400, 1600);
        // Wprost w JSONB: tak wygląda wiersz, w którym zadanie nie
        // dopisało jednego wariantu.
        DB::table('media')->where('id', $media->getKey())
            ->update(['metadata' => DB::raw("metadata #- '{variants,large}'")]);

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('brak wariantu large')
            ->assertFailed();
    }

    public function test_wariant_za_duzy_jest_porazka(): void
    {
        $media = $this->przetworzone(2400, 1600);
        $metadane = $media->metadata;
        $metadane['variants']['thumb']['width'] = 2400;
        $media->forceFill(['metadata' => $metadane])->save();

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('dłuższy bok nie może przekraczać 320')
            ->assertFailed();
    }

    public function test_nieobrocony_wariant_przy_orientacji_6_jest_porazka(): void
    {
        $media = $this->przetworzone(1800, 1200, 6);
        $metadane = $media->metadata;
        // Tak wyglądał błąd podwójnego obrotu (T11): wariant poziomy
        // z pionowo trzymanego telefonu.
        $metadane['variants']['feed']['width'] = 960;
        $metadane['variants']['feed']['height'] = 640;
        $media->forceFill(['metadata' => $metadane])->save();

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('zła orientacja')
            ->assertFailed();
    }

    public function test_brak_pliku_w_buckecie_wykrywa_tylko_pliki(): void
    {
        $media = $this->przetworzone(2400, 1600);
        Storage::disk('wariant601')->delete($media->wariant('feed')['key']);

        // Bez --pliki metadane są zgodne — i raport mówi wprost, że plików
        // nie sprawdzał, zamiast udawać pełny odbiór.
        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('Obecność plików w buckecie NIE była sprawdzana')
            ->assertSuccessful();

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD, '--pliki' => true])
            ->expectsOutputToContain('wariant feed nie leży w buckecie wariant601')
            ->assertFailed();
    }

    public function test_odrzucone_zdjecie_jest_porazka(): void
    {
        Storage::disk('oryginal601')->put('incoming/zepsute.jpg', 'to nie jest obraz');
        $media = Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'oryginal601',
            'variants_disk' => 'wariant601',
            'object_key' => 'incoming/zepsute.jpg',
            'status' => Media::STATUS_PENDING,
            'metadata' => [],
        ]);

        try {
            (new ProcessUploadedImage($media->getKey()))->handle();
        } catch (\Throwable) {
            // Zadanie rzuca po oznaczeniu `rejected` — o to tu chodzi.
        }

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('ODRZUCONE (rejected, processing_failed)')
            ->assertFailed();
    }

    public function test_swieze_pending_jest_w_toku_a_stare_utknelo(): void
    {
        $media = Media::factory()->pending()->create(['created_at' => now()->subMinutes(2)]);

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('jeszcze w toku')
            ->assertFailed();

        $media->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $this->artisan('kuking:odbior-zdjec', ['--media' => [$media->getKey()]])
            ->expectsOutputToContain('UTKNĘŁO')
            ->assertFailed();
    }

    public function test_nieudane_zadanie_w_failed_jobs_jest_porazka(): void
    {
        $this->przetworzone(800, 600);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'media',
            'payload' => json_encode(['displayName' => ProcessUploadedImage::class]),
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD])
            ->expectsOutputToContain('Nieudane zadania ProcessUploadedImage w failed_jobs od 2026-09-25T10:00:00+00:00: 1.')
            ->assertFailed();
    }

    public function test_zle_od_i_brak_zakresu_mowia_co_zrobic(): void
    {
        $this->artisan('kuking:odbior-zdjec', ['--od' => 'wczoraj po obiedzie'])
            ->expectsOutputToContain('nie jest datą')
            ->assertFailed();

        $this->artisan('kuking:odbior-zdjec')
            ->expectsOutputToContain('Podaj --od')
            ->assertFailed();
    }

    public function test_komenda_nie_pisze_do_bucketu_ani_do_bazy(): void
    {
        $media = $this->przetworzone(2400, 1600);
        $przed = DB::table('media')->where('id', $media->getKey())->first();

        $prawdziwy = Storage::disk('wariant601');
        Storage::set('wariant601', new class($prawdziwy->getDriver(), $prawdziwy->getAdapter(), $prawdziwy->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                throw new LogicException('odbiór nie może zapisywać');
            }

            public function delete($paths)
            {
                throw new LogicException('odbiór nie może kasować');
            }
        });

        $this->artisan('kuking:odbior-zdjec', ['--od' => self::OD, '--pliki' => true])->assertSuccessful();

        $this->assertEquals($przed, DB::table('media')->where('id', $media->getKey())->first());
    }
}
