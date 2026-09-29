<?php

declare(strict_types=1);

namespace App\Http\Requests\Collections;

use App\Models\Collection;
use App\Rules\CollectionNameNotTaken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wejście formularza zeszytu — założenie (`collections.store`) i zmiana
 * nazwy, opisu i widoczności (`collections.update`). Wyjęte z
 * `CollectionController::validateCollectionData()` bez zmiany zachowania
 * (issue #970, krok 5).
 *
 * Kolejność zostaje ta sama co w kontrolerze. Przy zmianie NAJPIERW Policy
 * `update` (`authorize()` woła `Gate::inspect()->authorize()` — ten sam 403
 * co `$this->authorize()`), dopiero potem pola; ktoś obcy nie dowiaduje się
 * z błędu pola, co wysłał źle. Przy założeniu Policy `create` zależy od
 * zwalidowanej widoczności, więc zostaje w kontrolerze, PO walidacji.
 *
 * Nazwa własnego, niezmienionego zeszytu nie jest dla niego „zajęta"
 * (`CollectionNameNotTaken::$ignoreCollectionId`); zakres nazw to właściciel
 * zeszytu przy zmianie, a zalogowana osoba przy założeniu.
 */
final class ZapisZeszytuRequest extends FormRequest
{
    public function authorize(): bool
    {
        $zeszyt = $this->zmienianyZeszyt();

        if ($zeszyt !== null) {
            Gate::inspect('update', $zeszyt)->authorize();
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $zeszyt = $this->zmienianyZeszyt();

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                new CollectionNameNotTaken(
                    (string) ($zeszyt?->owner_id ?? $this->user()->getKey()),
                    $zeszyt?->getKey(),
                ),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', 'in:public,private'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Podaj nazwę zeszytu — na przykład „Na święta”.',
            'name.min' => 'Nazwa zeszytu musi mieć co najmniej 2 znaki. Dopisz kilka liter.',
            // Bez tego wypadał szablon ogólny: „Pole «nazwa zeszytu» jest za
            // długie — może mieć najwyżej 120 znaków." Mówił, co jest źle,
            // ale nie mówił, co zrobić.
            'name.max' => 'Ta nazwa jest za długa. Zmieść się w 120 znakach — wystarczy krótka nazwa, na przykład „Na święta”.',
            'description.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — dwie opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
            /*
             * `required` BEZ WŁASNEGO ZDANIA wypadał jako szablon ogólny:
             * „Pole «widoczność» jest wymagane. Uzupełnij je, żeby wysłać
             * formularz." Na ekranie nie ma niczego o nazwie „widoczność" —
             * jest pytanie „Kto ma widzieć ten zeszyt?" i dwa przyciski
             * wyboru. Dla człowieka brak zaznaczenia i zaznaczenie czegoś
             * spoza listy to ta sama sytuacja, więc zdanie jest to samo.
             */
            'visibility.required' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
        ];
    }

    /**
     * Zeszyt z adresu przy zmianie; `null` przy zakładaniu nowego.
     */
    private function zmienianyZeszyt(): ?Collection
    {
        $zeszyt = $this->route('collection');

        return $zeszyt instanceof Collection ? $zeszyt : null;
    }
}
