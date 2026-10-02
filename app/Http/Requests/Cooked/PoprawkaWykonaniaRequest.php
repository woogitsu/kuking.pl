<?php

declare(strict_types=1);

namespace App\Http\Requests\Cooked;

use App\Models\CookedEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście formularza korekty własnego wykonania (`cooked.update`, #2459).
 *
 * Autoryzacja (`CookedEventPolicy::update`) stoi tu, przed walidacją, i jeszcze raz w kontrolerze.
 * Reguły i komunikaty trzech pól są TE SAME co przy zapisie
 * (`ZapisWykonaniaRequest::REGULY_POL_KOREKTY`). Cokolwiek poza trzema polami
 * i odciskiem `wersja` jest ignorowane już tutaj: `validated()` ich nie niesie.
 */
final class PoprawkaWykonaniaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wykonanie = $this->route('cookedEvent');

        // Obcy nie dostaje nawet komunikatów walidacji — najpierw Policy.
        return $wykonanie instanceof CookedEvent && ($this->user()?->can('update', $wykonanie) ?? false);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ZapisWykonaniaRequest::REGULY_POL_KOREKTY + [
            'wersja' => ['nullable', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ZapisWykonaniaRequest::KOMUNIKATY_POL_KOREKTY + [
            'wersja.size' => 'Ten formularz jest nieaktualny. Otwórz poprawkę jeszcze raz — Twój tekst zostanie w polach.',
        ];
    }

    public function wersjaFormularza(): ?string
    {
        $wersja = $this->input('wersja');

        return is_string($wersja) && $wersja !== '' ? $wersja : null;
    }
}
