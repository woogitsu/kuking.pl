<?php

declare(strict_types=1);

namespace App\Http\Requests\Cooked;

use App\Models\CookedEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wejście „Podziękuj" z ekranu „Komuś wyszło" (`cooked.thank`) — wyjęte
 * z `CookedEventController::thank()` bez zmiany zachowania (issue #970).
 * Najpierw Policy `celebrate`, potem walidacja — jak w kontrolerze.
 */
final class PodziekowanieRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wykonanie = $this->route('cookedEvent');

        if (! $wykonanie instanceof CookedEvent) {
            abort(404);
        }

        Gate::inspect('celebrate', $wykonanie)->authorize();

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Napisz albo zostaw gotowe podziękowanie, zanim wyślesz.',
        ];
    }
}
