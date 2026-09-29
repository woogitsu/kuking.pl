<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście „Wygeneruj nowe kody zapasowe" — reguły i komunikaty wyjęte
 * z `TwoFactorSettingsController::regenerateCodes()` bez zmiany zachowania
 * (issue #970).
 *
 * Błąd pola wraca do worka `regenerate`: na ekranie ustawień 2FA stoją obok
 * siebie dwa formularze z polem `password` (nowe kody i wyłączenie), a każdy
 * pokazuje tylko swój błąd.
 */
final class NoweKodyZapasoweRequest extends FormRequest
{
    protected $errorBag = 'regenerate';

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
            'password.required' => 'Wpisz hasło do Kuking, żeby dostać nowe kody zapasowe.',
        ];
    }
}
