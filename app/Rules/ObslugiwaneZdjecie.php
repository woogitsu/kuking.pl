<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RozpoznanieZdjecia;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Reguła walidacji zastępująca laravelowe `image` (audyt W3-08).
 *
 * DLACZEGO NIE `image`
 * `image` to w Laravelu `mimes:jpg,jpeg,png,gif,bmp,svg,webp`. Ta lista NIE
 * ZAWIERA AVIF, który `config/kuking.php` dopuszcza i który pole wyboru pliku
 * podpowiada — więc zdjęcie AVIF odpadało w formularzu, zanim potok mediów
 * zdążył powiedzieć, że umie je przetworzyć. Za to `image` PRZEPUSZCZA GIF,
 * BMP i SVG, których potok nie obsługuje. Czyli druga lista formatów,
 * rozjeżdżająca się z pierwszą w obie strony naraz.
 *
 * SVG jest tu osobno groźny: to dokument XML, który potrafi nieść skrypt.
 * Nie trafiłby na stronę (potok przekodowuje wszystko do WebP), ale reguła,
 * która go przepuszcza, jest złym miejscem do rozpoczynania obrony.
 *
 * Ta reguła pyta o to samo, o co pyta `StoreUploadedImage`, i tym samym kodem.
 * Nie zastępuje tamtego sprawdzenia — tamto jest prawdziwą granicą i musi
 * trzymać także wtedy, gdy ktoś ominie formularz. Ta istnieje po to, żeby
 * człowiek dostał komunikat PRZY POLU, razem z resztą błędów i bez utraty
 * wpisanego tekstu (docs/UX_50_PLUS.md).
 */
final class ObslugiwaneZdjecie implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.');

            return;
        }

        $problem = RozpoznanieZdjecia::coJestNieTak($value->getRealPath());

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
