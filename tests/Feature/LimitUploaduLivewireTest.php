<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LimityZdjec;
use Tests\TestCase;

/**
 * Zgoda limitu uploadu Livewire z `config/kuking.php` (issue #111).
 *
 * PO CO OSOBNY TEST, SKORO JEST JUŻ `UploadLimitsAgreementTest`
 * Tamten pilnuje zgody `config/kuking.php` z `docker/php.ini`. To jest TRZECIE,
 * niezależne miejsce, w którym rozmiar zdjęcia jest sprawdzany: Livewire ma
 * własną warstwę walidacji uploadu, we własnym pliku konfiguracyjnym pakietu,
 * i domyślnie przepuszcza 12 MB — o 3 MB mniej, niż produkt obiecuje na
 * ekranie „Dodaj”.
 *
 * Ten rozjazd nie był widoczny w żadnym teście, bo plik leci na endpoint
 * Livewire, zanim kod Kuking go zobaczy: zdjęcie 13 MB odpadało cicho,
 * pasek postępu znikał, a pole zdjęcia zostawało puste. Test porównuje obie
 * liczby, żeby zmiana jednej bez drugiej oblała od razu.
 */
class LimitUploaduLivewireTest extends TestCase
{
    /** @return list<string> */
    private function reguly(): array
    {
        $reguly = config('livewire.temporary_file_upload.rules');

        $this->assertIsArray(
            $reguly,
            'config/livewire.php ma `rules => null`, czyli domyślne 12 MB pakietu. '.
            'To jest dokładnie błąd z issue #111 — limit MUSI iść z LimityZdjec.',
        );

        return array_values(array_map('strval', $reguly));
    }

    public function test_limit_livewire_zgadza_sie_z_configiem_kuking(): void
    {
        $reguly = $this->reguly();

        $maksZReguly = null;

        foreach ($reguly as $regula) {
            if (str_starts_with($regula, 'max:')) {
                $maksZReguly = (int) substr($regula, 4);
            }
        }

        $this->assertNotNull($maksZReguly, 'Reguły uploadu Livewire nie mają w ogóle limitu `max:`.');

        $this->assertSame(
            LimityZdjec::maksKilobajtowDoWalidacji(),
            $maksZReguly,
            'Limit w config/livewire.php rozjechał się z config/kuking.php. Zdjęcie dozwolone '.
            'przez produkt odpadnie w kreatorze przepisu, zanim jakikolwiek kod Kuking je zobaczy.',
        );

        // 15 MB obiecane na ekranie, nie 12 MB z domyślnej wartości pakietu.
        $this->assertGreaterThanOrEqual(
            15 * 1024,
            $maksZReguly,
            'Kreator przyjmuje mniej, niż produkt obiecuje na ekranie „Dodaj”.',
        );
    }

    public function test_reguly_uploadu_nie_wycinaja_heic(): void
    {
        $reguly = $this->reguly();

        // `image` wygląda na oczywisty dodatek, a znaczy w Laravelu
        // `mimes:jpg,jpeg,png,gif,bmp,svg,webp` — bez HEIC/HEIF, czyli bez
        // formatu, w którym domyślnie fotografuje każdy nowszy iPhone.
        // Dodanie jej „dla porządku” zamieniłoby błąd rozmiaru na błąd formatu.
        $this->assertNotContains(
            'image',
            $reguly,
            'Reguła `image` wycina HEIC/HEIF, które config/kuking.php jawnie dopuszcza. '.
            'Czy plik jest obrazem, rozstrzygają magic bytes w StoreUploadedImage.',
        );

        foreach ($reguly as $regula) {
            $this->assertStringStartsNotWith(
                'mimes',
                $regula,
                'Typ pliku sprawdzamy po zawartości (magic bytes), nie po rozszerzeniu od klienta.',
            );
        }
    }

    public function test_kreator_pokazuje_polski_komunikat_gdy_wysylka_zdjecia_odpadnie(): void
    {
        // Zdarzenie `livewire-upload-error` niesie tylko `{id, property}` —
        // treści błędu z endpointu tam nie ma. Gdyby ten atrybut zniknął,
        // pasek postępu znowu znikałby bez słowa (issue #111).
        $widok = (string) file_get_contents(
            resource_path('views/components/recipe-wizard.blade.php'),
        );

        $this->assertStringContainsString(
            'data-blad-wysylki',
            $widok,
            'Pole zdjęcia w kreatorze nie niesie treści komunikatu dla nieudanej wysyłki.',
        );

        $skrypt = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'livewire-upload-error',
            $skrypt,
            'Nikt nie nasłuchuje `livewire-upload-error` — odrzucone zdjęcie znika bez komunikatu.',
        );

        // Liczba megabajtów w komunikacie ma iść z konfiguracji, a nie być
        // wpisana na sztywno w skrypcie ani w widoku.
        $this->assertStringContainsString(
            (string) LimityZdjec::maksMegabajtowDoKomunikatu(),
            LimityZdjec::komunikatNieudanejWysylki(),
        );

        $this->assertStringNotContainsString('MB', $skrypt);
    }
}
