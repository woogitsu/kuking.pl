<?php

declare(strict_types=1);

namespace App\Http\Requests\Cooked;

use App\Models\CookedEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wejście komentarza pod wykonaniem (`cooked.comment`) — wyjęte
 * z `CookedEventController::comment()` bez zmiany zachowania (issue #970).
 *
 * `comment`, nie `view`: zbanowany kucharz chowa wykonanie, ale nie zamyka
 * komentowania (decyzja właściciela do D-261). Policy idzie przed walidacją.
 */
final class KomentarzWykonaniaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wykonanie = $this->route('cookedEvent');

        if (! $wykonanie instanceof CookedEvent) {
            abort(404);
        }

        Gate::inspect('comment', $wykonanie)->authorize();

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
            // TEN SAM KOMUNIKAT CO POD WPISEM. Bez tej linii zostawał
            // domyślny tekst frameworka, czyli inny głos i inne słowo na to
            // samo pole na sąsiednim ekranie.
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ];
    }
}
