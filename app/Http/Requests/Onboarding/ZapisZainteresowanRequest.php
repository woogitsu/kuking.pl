<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Requests\TagSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście kroku „co lubisz gotować" (`onboarding.interests.save`) — wyjęte
 * z `OnboardingController::saveInterests()` bez zmiany zachowania (#970).
 *
 * `rules()` jest puste ŚWIADOMIE: walidacja tagów ma kilka oddzielnych faz
 * (rozmiar, format, jedno zapytanie o istnienie) i własne, polskie komunikaty
 * mówiące „wybierz z listy i zapisz ponownie" — siedzą w `TagSelection`,
 * wspólnym z ekranem obserwowania tagów. Nie duplikujemy ich tutaj.
 * Limit 50 tagów zostaje ten sam co przed przenosinami.
 */
final class ZapisZainteresowanRequest extends FormRequest
{
    /** Ile promowanych tagów można zaznaczyć w jednym zapisie. */
    public const NAJWIECEJ_TAGOW = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Zaznaczone tagi po pełnej walidacji (identyfikatory małymi literami,
     * bez powtórzeń). Błąd kończy się tym samym powrotem z komunikatem
     * co dotąd.
     *
     * @return list<string>
     */
    public function tagi(): array
    {
        return app(TagSelection::class)->validate($this, self::NAJWIECEJ_TAGOW);
    }
}
