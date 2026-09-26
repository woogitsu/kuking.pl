<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LimityZdjec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Tests\TestCase;

/**
 * Endpoint wgrywania plików Livewire (`livewire/upload-file`) przyjmuje tylko
 * zdjęcia i ma limit z `config/kuking.php` (audyt A5-09).
 *
 * Wcześniej: dowolny typ pliku do 15 MB, 60 plików na minutę na osobę,
 * a podgląd tymczasowy obejmował SVG, filmy i dźwięk. Pliki lądują na
 * produkcji w buckecie R2 pod `livewire-tmp/` i leżą tam do sprzątania.
 */
class WysylkaLivewirePrzyjmujeTylkoZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tmp-for-tests');
    }

    private function wyslij(UploadedFile $plik): TestResponse
    {
        // Skrypt Livewire wysyła plik przez XHR i czeka na JSON.
        return $this->postJson(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => [$plik]]);
    }

    public function test_zdjecie_przechodzi(): void
    {
        // Kontrola dodatnia: reguła nie może wyciąć zdjęcia, po które
        // kreator istnieje.
        $this->actingAs($this->user('kucharka'));

        $this->wyslij(UploadedFile::fake()->image('obiad.jpg', 800, 600))
            ->assertOk()
            ->assertJsonCount(1, 'paths');
    }

    public function test_plik_ktory_nie_jest_zdjeciem_odpada_mimo_rozszerzenia_jpg(): void
    {
        $this->actingAs($this->user('kucharka'));

        // Rozszerzenie, nazwa i typ od klienta nic nie znaczą — liczy się
        // zawartość. Zwykły `UploadedFile`, nie `UploadedFile::fake()`:
        // atrapa podaje typ z ROZSZERZENIA, a tu chodzi o dowód, że reguła
        // czyta zawartość.
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-nie-zdjecie-');
        file_put_contents($sciezka, str_repeat('to nie zdjęcie ', 100));

        try {
            $this->wyslij(new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true))
                ->assertStatus(422);
        } finally {
            @unlink($sciezka);
        }
    }

    public function test_gif_odpada_bo_nie_jest_na_liscie_obslugiwanych(): void
    {
        $this->actingAs($this->user('kucharka'));

        $this->wyslij(UploadedFile::fake()->image('obiad.gif', 20, 20))
            ->assertStatus(422);
    }

    public function test_reguly_biora_typy_z_konfiguracji(): void
    {
        $this->assertContains(
            'mimetypes:'.implode(',', LimityZdjec::dozwoloneTypy()),
            config('livewire.temporary_file_upload.rules'),
        );
    }

    public function test_podglad_tymczasowy_tylko_dla_formatow_zdjec(): void
    {
        $this->assertEqualsCanonicalizing(
            ['jpg', 'jpeg', 'png', 'webp'],
            config('livewire.temporary_file_upload.preview_mimes'),
        );
    }

    public function test_limit_wgrywania_z_konfiguracji_kuking(): void
    {
        $this->assertSame(
            'throttle:'.config('kuking.limits.livewire_upload').',livewire_upload',
            config('livewire.temporary_file_upload.middleware'),
        );

        [$ile] = explode(',', (string) config('kuking.limits.livewire_upload'));

        // Ciaśniej niż domyślne `throttle:60,1` pakietu.
        $this->assertLessThan(60, (int) $ile);

        $this->actingAs($this->user('kucharka'));

        for ($i = 0; $i < (int) $ile; $i++) {
            $this->assertNotSame(429, $this->wyslij(UploadedFile::fake()->image("krok{$i}.jpg", 10, 10))->status());
        }

        $this->wyslij(UploadedFile::fake()->image('za-duzo.jpg', 10, 10))->assertStatus(429);
    }
}
