<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\Filesystem as PlikiLokalne;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use Tests\TestCase;

/**
 * #2178: udany zapis kasuje źródło z `livewire-tmp/` od razu
 * (`StoreUploadedImage`), ale plik porzucony przed zapisem — zły format,
 * zamknięta karta, nieudane usunięcie — leżał w prywatnym R2 do KOLEJNEGO
 * uploadu, bo tylko wtedy sprząta Livewire. `kuking:sprzataj-porzucone-uploady`
 * jest linią obrony w kodzie; reguła lifecycle R2 (#2051) jest drugą.
 */
class PorzuconeUploadyLivewireSaSprzataneTest extends TestCase
{
    private string $katalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->katalog = sys_get_temp_dir().'/kuking-porzucone-uploady-'.bin2hex(random_bytes(4));

        Storage::extend('udawany-zdalny-uploadow', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['katalog']);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => '']);
        });

        config([
            'filesystems.disks.udawany-r2-uploadow' => ['driver' => 'udawany-zdalny-uploadow', 'katalog' => $this->katalog],
            'livewire.temporary_file_upload.disk' => 'udawany-r2-uploadow',
            'livewire.temporary_file_upload.directory' => null,
        ]);
    }

    protected function tearDown(): void
    {
        (new PlikiLokalne)->deleteDirectory($this->katalog);

        parent::tearDown();
    }

    private function dysk(): FilesystemContract
    {
        return Storage::disk('udawany-r2-uploadow');
    }

    /** Kładzie obiekt i ustawia mu wiek (mtime = czas zapisu w R2). */
    private function poloz(string $klucz, int $godzinTemu): void
    {
        $this->dysk()->put($klucz, 'zawartosc');
        touch($this->katalog.'/'.$klucz, time() - $godzinTemu * 3600);
        clearstatcache();
    }

    public function test_kasuje_stare_uploady_razem_z_json_a_swiezych_i_cudzych_prefiksow_nie_rusza(): void
    {
        $this->poloz('livewire-tmp/stary-zdjecie.jpg', 30);
        $this->poloz('livewire-tmp/stary-zdjecie.jpg.json', 30);
        $this->poloz('livewire-tmp/swiezy-zdjecie.jpg', 2);
        $this->poloz('livewire-tmp/swiezy-zdjecie.jpg.json', 2);
        // Stare, ale nie nasze: oryginały, warianty i cokolwiek o podobnej nazwie.
        $this->poloz('incoming/1/2026/09/oryginal.jpg', 400);
        $this->poloz('livewire-tmp-inny/stary.jpg', 400);
        $this->poloz('media/wariant.webp', 400);

        $this->artisan('kuking:sprzataj-porzucone-uploady')
            ->expectsOutputToContain('Skasowano 2 porzucone pliki starszych niż 24 h.')
            ->assertSuccessful();

        $this->dysk()->assertMissing('livewire-tmp/stary-zdjecie.jpg');
        $this->dysk()->assertMissing('livewire-tmp/stary-zdjecie.jpg.json');
        $this->dysk()->assertExists('livewire-tmp/swiezy-zdjecie.jpg');
        $this->dysk()->assertExists('livewire-tmp/swiezy-zdjecie.jpg.json');
        $this->dysk()->assertExists('incoming/1/2026/09/oryginal.jpg');
        $this->dysk()->assertExists('livewire-tmp-inny/stary.jpg');
        $this->dysk()->assertExists('media/wariant.webp');
    }

    public function test_na_sucho_liczy_ale_niczego_nie_kasuje(): void
    {
        $this->poloz('livewire-tmp/stary-zdjecie.jpg', 30);

        $this->artisan('kuking:sprzataj-porzucone-uploady', ['--na-sucho' => true])
            ->expectsOutputToContain('Do skasowania: 1 porzucony plik starszych niż 24 h.')
            ->assertSuccessful();

        $this->dysk()->assertExists('livewire-tmp/stary-zdjecie.jpg');
    }

    public function test_opcja_godziny_przesuwa_granice_ale_nie_zejdzie_ponizej_godziny(): void
    {
        $this->poloz('livewire-tmp/sprzed-pieciu-godzin.jpg', 5);
        $this->poloz('livewire-tmp/sprzed-pol-godziny.jpg', 0);

        $this->artisan('kuking:sprzataj-porzucone-uploady', ['--godziny' => 0])->assertSuccessful();

        // `--godziny=0` zostaje podniesione do 1 h: plik sprzed pół godziny (tu: sprzed chwili) zostaje.
        $this->dysk()->assertMissing('livewire-tmp/sprzed-pieciu-godzin.jpg');
        $this->dysk()->assertExists('livewire-tmp/sprzed-pol-godziny.jpg');
    }

    public function test_pusty_katalog_livewire_nie_oznacza_calego_dysku(): void
    {
        config(['livewire.temporary_file_upload.directory' => '/']);
        $this->poloz('incoming/1/oryginal.jpg', 400);
        $this->poloz('cokolwiek.txt', 400);

        $this->artisan('kuking:sprzataj-porzucone-uploady')
            ->expectsOutputToContain('Skasowano 0 porzuconych plików')
            ->assertSuccessful();

        $this->dysk()->assertExists('incoming/1/oryginal.jpg');
        $this->dysk()->assertExists('cokolwiek.txt');
    }

    public function test_porazka_usuniecia_konczy_komende_bledem_i_nie_zdradza_klucza_w_dzienniku(): void
    {
        $magazyn = Mockery::mock(FilesystemContract::class);
        $magazyn->shouldReceive('files')->andReturn(['livewire-tmp/tajna-nazwa-losowa.jpg']);
        $magazyn->shouldReceive('lastModified')->andReturn(time() - 30 * 3600);
        $magazyn->shouldReceive('delete')->andThrow(new \RuntimeException('R2 niedostępne dla livewire-tmp/tajna-nazwa-losowa.jpg'));
        Storage::set('udawany-r2-uploadow', $magazyn);

        $ostrzezenia = [];
        Log::listen(function ($wpis) use (&$ostrzezenia): void {
            $ostrzezenia[] = $wpis->message.' '.json_encode($wpis->context);
        });

        $this->artisan('kuking:sprzataj-porzucone-uploady')
            ->expectsOutputToContain('Nie udało się skasować 1 pozycji')
            ->assertFailed();

        $this->assertNotSame([], $ostrzezenia, 'Porażka usunięcia musi zostawić ślad w dzienniku.');
        foreach ($ostrzezenia as $wpis) {
            $this->assertStringNotContainsString('tajna-nazwa-losowa', $wpis);
        }
    }

    public function test_cichy_false_z_delete_nie_udaje_sukcesu(): void
    {
        $magazyn = Mockery::mock(FilesystemContract::class);
        $magazyn->shouldReceive('files')->andReturn(['livewire-tmp/a.jpg']);
        $magazyn->shouldReceive('lastModified')->andReturn(time() - 30 * 3600);
        $magazyn->shouldReceive('delete')->andReturn(false);
        $magazyn->shouldReceive('exists')->andReturn(true);
        Storage::set('udawany-r2-uploadow', $magazyn);

        $this->artisan('kuking:sprzataj-porzucone-uploady')->assertFailed();
    }

    public function test_komenda_jest_w_harmonogramie_codziennie_na_jednym_serwerze(): void
    {
        $zdarzenia = collect(app(Schedule::class)->events())
            ->filter(fn ($z) => ($z->description ?? '') === 'kuking:sprzataj-porzucone-uploady');

        $this->assertCount(1, $zdarzenia);
        $this->assertSame('30 3 * * *', $zdarzenia->first()->expression);
        $this->assertTrue($zdarzenia->first()->onOneServer);
    }
}
