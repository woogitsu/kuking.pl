<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście „Usuń konto" z `/ustawienia/twoje-dane` — reguły i komunikaty
 * wyjęte z `DataSettingsController::requestDeletion()` bez zmiany zachowania
 * (issue #970).
 *
 * ZAKRES USUNIĘCIA WYBIERA CZŁOWIEK (D-022). Haczyk „usuń także moje
 * treści" jest DOMYŚLNIE ODHACZONY i dlatego nie ma tu reguły `required`:
 * brak pola w żądaniu to poprawna, najczęstsza odpowiedź, znacząca „zostaw
 * teksty". `boolean` pilnuje tylko, żeby nie dało się wcisnąć tam czegoś
 * innego niż tak/nie.
 *
 * Poprawności HASŁA tu nie ma — świadomie. Przy złym haśle kontroler wraca
 * z wpisanym wyłącznie `usun_tresci` (bez odhaczonego „rozumiem"), a porażka
 * reguł FormRequesta oddaje całe wejście poza hasłem. Przeniesienie sprawdzenia
 * do `after()` zmieniłoby więc to, co człowiek zastaje w formularzu.
 */
final class ProsbaOUsuniecieKontaRequest extends FormRequest
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
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
            'usun_tresci' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Wpisz swoje hasło, żeby potwierdzić, że to Ty.',
            'confirm.accepted' => 'Zaznacz, że rozumiesz, co się stanie.',
            'usun_tresci.boolean' => 'Zaznacz haczyk albo zostaw go pustym.',
        ];
    }

    /**
     * Wybór idzie do KOLUMNY, nie do sesji ani do zadania w kolejce —
     * egzekucja jest 30 dni później (D-022, punkt 2).
     */
    public function zakres(): string
    {
        return $this->boolean('usun_tresci') ? User::DELETE_SCOPE_EVERYTHING : User::DELETE_SCOPE_MINIMUM;
    }
}
