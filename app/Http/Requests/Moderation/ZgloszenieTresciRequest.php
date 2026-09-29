<?php

declare(strict_types=1);

namespace App\Http\Requests\Moderation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Wejście formularza zgłoszenia treści (`reports.store`) — wyjęte
 * z `ReportController::store()` bez zmiany zachowania (issue #970).
 *
 * KOLEJNOŚĆ SPRAWDZEŃ ZOSTAJE TA SAMA CO W KONTROLERZE
 *  1. limit żądań (`throttle:report`) — middleware trasy, działa PRZED
 *     rozwiązaniem tego żądania;
 *  2. zgłoszenie konta pod nazwą, nie pod UUID — odsyłka z kontrolera
 *     (issue #1599), przed regułami pól;
 *  3. reguły pól (`rules()`);
 *  4. istnienie celu (404) i Policy widoczności — w `ReportContent`
 *     (`authorize()` / `handle()`), bo bramka ma stać w Domain, a nie w tym
 *     żądaniu (audyt W7-05, AGENTS.md §4). Dlatego `authorize()` tutaj
 *     zwraca `true` i NIE rozstrzyga widoczności celu.
 *
 * Punkt 2 wymaga, żeby przy zgłoszeniu konta pod nazwą reguły pól nie
 * odrzuciły żądania pierwsze: dawniej odsyłka stała przed `validate()`,
 * więc człowiek dostawał zdanie „Sprawdź, czy to na pewno ta osoba", a nie
 * błąd pola. Reguły są wtedy puste, a kontroler kończy na odsyłce.
 */
final class ZgloszenieTresciRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        if ($this->zgloszenieKontaPodNazwa()) {
            return [];
        }

        return [
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Wybierz, co jest nie tak z tą treścią.',
            // Bez tego wypadał szablon ogólny: „Pole «szczegóły zgłoszenia»
            // jest za długie — może mieć najwyżej 2000 znaków." Na ekranie
            // nie ma niczego o nazwie „szczegóły zgłoszenia" — jest pytanie
            // „Chcesz coś dopisać?" — a zdanie nie mówiło, co zrobić.
            'details.max' => 'To jest za długie. Zmieść się w 2000 znakach — napisz samo to, co najważniejsze.',
        ];
    }

    /**
     * Zgłoszenie konta wysłane pod nazwą użytkownika, nie pod UUID
     * (issue #1599) — kontroler odsyła je na formularz.
     */
    public function zgloszenieKontaPodNazwa(): bool
    {
        return $this->route('type') === 'user' && ! Str::isUuid((string) $this->route('id'));
    }
}
