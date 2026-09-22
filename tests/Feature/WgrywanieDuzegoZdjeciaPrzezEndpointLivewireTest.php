<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LimityZdjec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Tests\TestCase;

/**
 * Regresja issue #111 — ale na PRAWDZIWYM endpoincie Livewire
 * (`POST /livewire/upload-file`), nie tylko na porównaniu liczb w configu.
 *
 * `LimitUploaduLivewireTest` pilnuje, że `config('livewire.temporary_file_upload.rules')`
 * ZGADZA SIĘ z `LimityZdjec` — to złapało błąd z issue #111 (sprawdzone: cofnięcie
 * łatki w `config/livewire.php` psuje dokładnie ten test). Ten plik idzie o krok
 * dalej i uderza w sam endpoint, na który Livewire wysyła zdjęcie NATYCHMIAST
 * po wyborze pliku w kreatorze — czyli w to samo miejsce, które w issue #111
 * cicho odrzucało zdjęcie 13 MB, zanim jakikolwiek kod Kuking je zobaczył.
 *
 * DLACZEGO `->size()`, A NIE PRAWDZIWE 13 MB NA DYSKU
 * `UploadedFile::fake()->image(...)->size($kb)` NIE zapisuje $kb kilobajtów
 * treści — na dysku ląduje kilkaset bajtów prawdziwego JPEG-a, a `getSize()`
 * jest podmienione tak, żeby ZWRACAĆ zadaną liczbę
 * (`Illuminate\Http\Testing\File::getSize()`). Walidacja Laravela (`max:`)
 * czyta dokładnie `getSize()`, więc test sprawdza PRAWDZIWĄ granicę rozmiaru
 * bez generowania ani wysyłania naprawdę kilkunastu megabajtów. Ten sam trick
 * już mieszka w tym repo (`MediaUploadTest`, `SygnalNieudanegoWgraniaZFormularzaTest`),
 * tu tylko przy liczbie z samego środka przedziału z issue (12–15 MB), a nie
 * przy dowolnie małej wartości.
 */
class WgrywanieDuzegoZdjeciaPrzezEndpointLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tmp-for-tests');
    }

    /**
     * Podpisany adres endpointu Livewire — dokładnie ten, na który leci
     * żądanie z przeglądarki po wyborze pliku (`GenerateSignedUploadUrl::forLocal()`).
     * Endpoint odrzuca żądanie z 401, jeśli podpis się nie zgadza
     * (`FileUploadController::handle()`), więc nie da się tego obejść zwykłym
     * `route('livewire.upload-file')`.
     */
    private function podpisanyAdresUploadu(): string
    {
        return app(GenerateSignedUploadUrl::class)->forLocal();
    }

    public function test_zdjecie_z_telefonu_13_mb_przechodzi_przez_prawdziwy_endpoint_livewire(): void
    {
        $this->actingAs($this->user('halina'));

        // 13 MB — w środku przedziału z issue #111 (12–15 MB), realistyczna
        // waga zdjęcia HEIC z nowszego iPhone'a.
        $zdjecieZTelefonu = UploadedFile::fake()
            ->image('sernik-swiateczny.jpg', 3024, 4032)
            ->size(13 * 1024);

        $odpowiedz = $this->postJson($this->podpisanyAdresUploadu(), [
            'files' => [$zdjecieZTelefonu],
        ]);

        $odpowiedz->assertOk();
        $this->assertArrayHasKey(
            'paths',
            $odpowiedz->json(),
            'Endpoint uploadu Livewire powinien zwrócić ścieżkę zapisanego pliku tymczasowego.',
        );
    }

    public function test_zdjecie_powyzej_limitu_nadal_odrzucone_przez_endpoint_livewire(): void
    {
        $this->actingAs($this->user('halina'));

        // O 1 MB więcej, niż wolno — limit ma dalej działać, a nie zniknąć
        // razem z naprawą issue #111.
        $zaDuzeZdjecie = UploadedFile::fake()
            ->image('sernik-swiateczny.jpg', 3024, 4032)
            ->size(LimityZdjec::maksKilobajtowDoWalidacji() + 1024);

        $odpowiedz = $this->postJson($this->podpisanyAdresUploadu(), [
            'files' => [$zaDuzeZdjecie],
        ]);

        $odpowiedz->assertStatus(422);
    }
}
