<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście „Potwierdź i włącz" z ekranu włączenia 2FA — reguły i komunikaty
 * wyjęte z `TwoFactorSettingsController::confirm()` bez zmiany zachowania
 * (issue #970).
 *
 * Poprawności hasła i kodu tu nie ma: sprawdza je `WlaczDwuetapowa` w ustalonej
 * kolejności (najpierw hasło, kod dopiero po nim — #1376, D-245). Błąd pól
 * wraca do domyślnego worka błędów, tak jak przed wyjęciem.
 */
final class WlaczenieDwuetapowejRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Trasa stoi za `auth`; osoby tu nie wybieramy — zawsze `user()`.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Wpisz sześciocyfrowy kod z aplikacji.',
            'password.required' => 'Wpisz hasło do Kuking, żeby włączyć weryfikację dwuetapową.',
        ];
    }
}
