<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście „Wyłącz weryfikację dwuetapową" — reguły i komunikaty wyjęte
 * z `TwoFactorSettingsController::disable()` bez zmiany zachowania
 * (issue #970). Błąd pola wraca do worka `disable` (patrz
 * `NoweKodyZapasoweRequest`).
 */
final class WylaczenieDwuetapowejRequest extends FormRequest
{
    protected $errorBag = 'disable';

    public function authorize(): bool
    {
        // Trasa stoi za `auth`; osoby tu nie wybieramy — zawsze `user()`.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Wpisz hasło do Kuking, żeby wyłączyć weryfikację dwuetapową.',
        ];
    }
}
