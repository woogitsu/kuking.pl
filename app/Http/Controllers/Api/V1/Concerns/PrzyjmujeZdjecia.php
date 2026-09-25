<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wspólne dla tras API przyjmujących zdjęcia (D-273) — te same reguły co
 * formularze WWW (`LimityZdjec`, `ObslugiwaneZdjecie`) i ten sam zapis przez
 * `StoreUploadedImage`: rozmiar, magic bytes, limit megapikseli, własny klucz,
 * a potem zadanie w tle, które re-enkoduje plik i zdejmuje EXIF/GPS.
 * Nic tu nie omija tej drogi.
 */
trait PrzyjmujeZdjecia
{
    /**
     * @return array<string, mixed>
     */
    protected function regulyZdjec(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'photos.*' => ['file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function komunikatyZdjec(): array
    {
        return [
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
        ];
    }

    /**
     * @return list<string> identyfikatory zapisanych zdjęć
     */
    protected function zapiszZdjecia(Request $request, User $wlasciciel, StoreUploadedImage $zapis): array
    {
        $identyfikatory = [];

        try {
            foreach ($request->file('photos', []) as $plik) {
                $identyfikatory[] = (string) $zapis->handle($wlasciciel, $plik)->getKey();
            }
        } catch (BladDlaCzlowieka $e) {
            throw ValidationException::withMessages(['photos' => $e->getMessage()]);
        }

        return $identyfikatory;
    }

    /**
     * Tożsamość jednego wysłania — nagłówek `Idempotency-Key` (UUID) w roli
     * ukrytego pola `klucz_wyslania` z formularza WWW. Aplikacja ponawiająca
     * wysyłkę przy słabym zasięgu nie tworzy drugiego wpisu (ten sam
     * mechanizm co ADR_IDEMPOTENCJA_FORMULARZY).
     */
    protected function kluczWyslania(Request $request): ?string
    {
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $request->header('Idempotency-Key');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }
}
