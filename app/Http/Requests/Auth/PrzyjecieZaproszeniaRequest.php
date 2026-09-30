<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Wejscie;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście „Zakładam konto" z ekranu zaproszenia (`POST /zaproszenie/zakladam`)
 * — odczyt tokenu wyjęty z `RegistrationInviteController::przyjmij()` bez
 * zmiany zachowania (issue #970).
 *
 * REGUŁ TU NIE MA — świadomie. Token brakujący, pusty, wygasły, zużyty
 * i nigdy nieistniejący mają dać TEN SAM wynik (przekierowanie na `/register`
 * z jednym zdaniem); reguła, która odsyłałaby z błędem pola, byłaby
 * wyrocznią „ten token istniał, tamten nie". Nie ma też wejścia bez tokenu,
 * które trzeba by zatrzymać: goście też przyjmują zaproszenie, więc
 * `authorize()` przepuszcza każdego, a dostępu pilnuje ważność tokenu.
 */
final class PrzyjecieZaproszeniaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** Jawny token z formularza; brak pola albo tablica to pusty napis, nie błąd. */
    public function token(): string
    {
        return Wejscie::tekst($this->input('token'));
    }
}
